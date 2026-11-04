<?php

namespace App\Services;

use App\Models\ClientStorageTarget;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;

class ClientConfigStorageService
{
    private $client;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false]);
    }

    public function upload(ClientStorageTarget $target, string $objectKey, string $body): array
    {
        $target->assertCloudAccountEnabled();
        list($accessKeyLabel, $secretKeyLabel) = $this->credentialLabels($target);
        $accessKey = $this->decrypt($target->access_key_id_encrypted, $accessKeyLabel);
        $secretKey = $this->decrypt($target->secret_key_encrypted, $secretKeyLabel);
        if ($target->provider === 'aliyun_oss') {
            return $this->uploadAliyun($target, $objectKey, $body, $accessKey, $secretKey);
        }
        if ($target->provider === 'tencent_cos') {
            return $this->uploadTencent($target, $objectKey, $body, $accessKey, $secretKey);
        }
        if ($target->provider === 'ucloud_us3') {
            return $this->uploadUcloud($target, $objectKey, $body, $accessKey, $secretKey);
        }
        throw new \RuntimeException('不支持的云存储类型');
    }

    public function verifyPublicObject(string $url, string $expectedBody): void
    {
        $separator = strpos($url, '?') === false ? '?' : '&';
        $response = $this->client->get($url . $separator . '_verify=' . time(), [
            'headers' => ['Cache-Control' => 'no-cache'],
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException('发布后校验失败：公开地址返回 HTTP ' . $response->getStatusCode());
        }
        $actual = (string)$response->getBody();
        if (!hash_equals(hash('sha256', $expectedBody), hash('sha256', $actual))) {
            throw new \RuntimeException('发布后校验失败：远端文件内容与本次发布不一致');
        }
    }

    public function deleteObject(ClientStorageTarget $target, string $objectKey): void
    {
        $target->assertCloudAccountEnabled();
        list($accessKeyLabel, $secretKeyLabel) = $this->credentialLabels($target);
        $accessKey = $this->decrypt($target->access_key_id_encrypted, $accessKeyLabel);
        $secretKey = $this->decrypt($target->secret_key_encrypted, $secretKeyLabel);
        if ($target->provider === 'aliyun_oss') {
            $this->deleteAliyun($target, $objectKey, $accessKey, $secretKey);
            return;
        }
        if ($target->provider === 'tencent_cos') {
            $this->deleteTencent($target, $objectKey, $accessKey, $secretKey);
            return;
        }
        if ($target->provider === 'ucloud_us3') {
            $this->deleteUcloud($target, $objectKey, $accessKey, $secretKey);
            return;
        }
        throw new \RuntimeException('不支持的云存储类型');
    }

    public function publicUrlFor(ClientStorageTarget $target, string $objectKey): string
    {
        $baseKey = ltrim((string)$target->object_key, '/');
        $public = (string)$target->public_url;
        if ($baseKey !== '' && substr($public, -strlen($baseKey)) === $baseKey) {
            return substr($public, 0, -strlen($baseKey)) . ltrim($objectKey, '/');
        }
        return rtrim($public, '/') . '/' . $this->encodePath($objectKey);
    }

    public function defaultPublicUrlFor(ClientStorageTarget $target, string $objectKey): string
    {
        if ($target->provider === 'ucloud_us3') {
            list($url) = $this->ucloudNativeAddress($target, $objectKey);
            return $url;
        }
        list($url) = $this->targetAddress($target, $objectKey);
        return $url;
    }

    public function versionedObjectKey(ClientStorageTarget $target, int $version): string
    {
        $key = ltrim((string)$target->object_key, '/');
        $slash = strrpos($key, '/');
        $prefix = $slash === false ? '' : substr($key, 0, $slash + 1);
        return $prefix . 'releases/config-v' . $version . '.json';
    }

    private function uploadAliyun(ClientStorageTarget $target, string $objectKey, string $body, string $accessKey, string $secretKey): array
    {
        list($url, $host, $path) = $this->targetAddress($target, $objectKey);
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $contentType = 'application/octet-stream';
        $contentMd5 = base64_encode(md5($body, true));
        $canonicalResource = '/' . $target->bucket . '/' . ltrim($objectKey, '/');
        $token = $this->optionalDecrypt($target->security_token_encrypted);
        $ossHeaders = [];
        if ($this->usesAutomaticPublicUrl($target)) $ossHeaders['x-oss-object-acl'] = 'public-read';
        if ($token) $ossHeaders['x-oss-security-token'] = $token;
        ksort($ossHeaders);
        $canonicalHeaders = '';
        foreach ($ossHeaders as $name => $value) $canonicalHeaders .= $name . ':' . $value . "\n";
        $stringToSign = "PUT\n{$contentMd5}\n{$contentType}\n{$date}\n{$canonicalHeaders}{$canonicalResource}";
        $headers = [
            'Host' => $host,
            'Date' => $date,
            'Content-MD5' => $contentMd5,
            'Content-Type' => $contentType,
            'Authorization' => 'OSS ' . $accessKey . ':' . base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true)),
        ];
        if ($this->usesAutomaticPublicUrl($target)) $headers['x-oss-object-acl'] = 'public-read';
        if ($token) $headers['x-oss-security-token'] = $token;
        return $this->put($url, $headers, $body);
    }

    private function uploadTencent(ClientStorageTarget $target, string $objectKey, string $body, string $accessKey, string $secretKey): array
    {
        list($url, $host, $path) = $this->targetAddress($target, $objectKey);
        $now = time();
        $keyTime = $now . ';' . ($now + 600);
        $contentType = 'application/octet-stream';
        $contentMd5 = base64_encode(md5($body, true));
        $signedHeaders = [
            'content-md5' => $contentMd5,
            'content-type' => $contentType,
            'host' => strtolower($host),
        ];
        if ($this->usesAutomaticPublicUrl($target)) $signedHeaders['x-cos-acl'] = 'public-read';
        $token = $this->optionalDecrypt($target->security_token_encrypted);
        if ($token) $signedHeaders['x-cos-security-token'] = $token;
        ksort($signedHeaders);
        $canonicalHeaders = implode('&', array_map(function ($key) use ($signedHeaders) {
            return rawurlencode($key) . '=' . rawurlencode($signedHeaders[$key]);
        }, array_keys($signedHeaders)));
        $headerList = implode(';', array_keys($signedHeaders));
        $httpString = "put\n{$path}\n\n{$canonicalHeaders}\n";
        $signKey = hash_hmac('sha1', $keyTime, $secretKey);
        $stringToSign = "sha1\n{$keyTime}\n" . sha1($httpString) . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);
        $authorization = 'q-sign-algorithm=sha1&q-ak=' . rawurlencode($accessKey)
            . '&q-sign-time=' . $keyTime . '&q-key-time=' . $keyTime
            . '&q-header-list=' . $headerList . '&q-url-param-list=&q-signature=' . $signature;
        $headers = ['Host' => $host, 'Content-Type' => $contentType, 'Content-MD5' => $contentMd5, 'Authorization' => $authorization];
        if ($this->usesAutomaticPublicUrl($target)) $headers['x-cos-acl'] = 'public-read';
        if ($token) $headers['x-cos-security-token'] = $token;
        return $this->put($url, $headers, $body);
    }

    private function uploadUcloud(ClientStorageTarget $target, string $objectKey, string $body, string $accessKey, string $secretKey): array
    {
        list($url, $host) = $this->ucloudNativeAddress($target, $objectKey);
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $contentType = 'application/octet-stream';
        $canonicalResource = '/' . $target->bucket . '/' . ltrim($objectKey, '/');
        $stringToSign = "PUT\n\n{$contentType}\n{$date}\n{$canonicalResource}";
        $headers = [
            'Host' => $host,
            'Date' => $date,
            'Content-Type' => $contentType,
            'Authorization' => 'UCloud ' . $accessKey . ':' . base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true)),
        ];
        $result = $this->put($url, $headers, $body);
        if ($this->usesAutomaticPublicUrl($target)) {
            try {
                $this->setUcloudPublicReadAcl($target, $objectKey, $accessKey, $secretKey);
            } catch (\Throwable $error) {
                try {
                    $this->deleteUcloud($target, $objectKey, $accessKey, $secretKey);
                } catch (\Throwable $cleanupError) {
                    throw new \RuntimeException($error->getMessage() . '；已上传的私有文件未能自动删除：' . $cleanupError->getMessage(), 0, $error);
                }
                throw $error;
            }
        }
        return $result;
    }

    private function setUcloudPublicReadAcl(ClientStorageTarget $target, string $objectKey, string $accessKey, string $secretKey): void
    {
        list($url, $host, $path) = $this->ucloudS3Address($target, $objectKey);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);
        $region = 'us-east-1';
        $service = 's3';
        $payloadHash = hash('sha256', '');
        $signedHeaders = [
            'host' => strtolower($host),
            'x-amz-acl' => 'public-read',
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        $token = $this->optionalDecrypt($target->security_token_encrypted);
        if ($token) $signedHeaders['x-amz-security-token'] = $token;
        ksort($signedHeaders);

        $canonicalHeaders = '';
        foreach ($signedHeaders as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim(preg_replace('/\s+/', ' ', $value)) . "\n";
        }
        $signedHeaderNames = implode(';', array_keys($signedHeaders));
        $canonicalRequest = "PUT\n{$path}\nacl=\n{$canonicalHeaders}\n{$signedHeaderNames}\n{$payloadHash}";
        $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $service, $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers = [
            'Host' => $host,
            'x-amz-acl' => 'public-read',
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $credentialScope
                . ', SignedHeaders=' . $signedHeaderNames . ', Signature=' . $signature,
        ];
        if ($token) $headers['x-amz-security-token'] = $token;

        $response = $this->client->put($url . '?acl', ['headers' => $headers, 'body' => '']);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $message = trim((string)$response->getBody());
            throw new \RuntimeException(
                'UCloud 单文件公有读设置失败（HTTP ' . $status . '）：'
                . mb_substr($message, 0, 500)
                . '。请确认当前地域支持 PutObjectAcl，且公钥/私钥属于 Bucket 创建账户'
            );
        }
    }

    private function deleteAliyun(ClientStorageTarget $target, string $objectKey, string $accessKey, string $secretKey): void
    {
        list($url, $host) = $this->targetAddress($target, $objectKey);
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $canonicalResource = '/' . $target->bucket . '/' . ltrim($objectKey, '/');
        $token = $this->optionalDecrypt($target->security_token_encrypted);
        $canonicalHeaders = $token ? 'x-oss-security-token:' . $token . "\n" : '';
        $stringToSign = "DELETE\n\n\n{$date}\n{$canonicalHeaders}{$canonicalResource}";
        $headers = [
            'Host' => $host,
            'Date' => $date,
            'Authorization' => 'OSS ' . $accessKey . ':' . base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true)),
        ];
        if ($token) $headers['x-oss-security-token'] = $token;
        $this->delete($url, $headers);
    }

    private function deleteTencent(ClientStorageTarget $target, string $objectKey, string $accessKey, string $secretKey): void
    {
        list($url, $host, $path) = $this->targetAddress($target, $objectKey);
        $now = time();
        $keyTime = $now . ';' . ($now + 600);
        $signedHeaders = ['host' => strtolower($host)];
        $token = $this->optionalDecrypt($target->security_token_encrypted);
        if ($token) $signedHeaders['x-cos-security-token'] = $token;
        ksort($signedHeaders);
        $canonicalHeaders = implode('&', array_map(function ($key) use ($signedHeaders) {
            return rawurlencode($key) . '=' . rawurlencode($signedHeaders[$key]);
        }, array_keys($signedHeaders)));
        $headerList = implode(';', array_keys($signedHeaders));
        $httpString = "delete\n{$path}\n\n{$canonicalHeaders}\n";
        $signKey = hash_hmac('sha1', $keyTime, $secretKey);
        $stringToSign = "sha1\n{$keyTime}\n" . sha1($httpString) . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);
        $authorization = 'q-sign-algorithm=sha1&q-ak=' . rawurlencode($accessKey)
            . '&q-sign-time=' . $keyTime . '&q-key-time=' . $keyTime
            . '&q-header-list=' . $headerList . '&q-url-param-list=&q-signature=' . $signature;
        $headers = ['Host' => $host, 'Authorization' => $authorization];
        if ($token) $headers['x-cos-security-token'] = $token;
        $this->delete($url, $headers);
    }

    private function deleteUcloud(ClientStorageTarget $target, string $objectKey, string $accessKey, string $secretKey): void
    {
        list($url, $host) = $this->ucloudNativeAddress($target, $objectKey);
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $canonicalResource = '/' . $target->bucket . '/' . ltrim($objectKey, '/');
        $stringToSign = "DELETE\n\n\n{$date}\n{$canonicalResource}";
        $headers = [
            'Host' => $host,
            'Date' => $date,
            'Authorization' => 'UCloud ' . $accessKey . ':' . base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true)),
        ];
        $this->delete($url, $headers);
    }

    private function targetAddress(ClientStorageTarget $target, string $objectKey): array
    {
        $rawEndpoint = trim((string)$target->endpoint);
        $scheme = stripos($rawEndpoint, 'http://') === 0 ? 'http' : 'https';
        $endpoint = preg_replace('#^https?://#i', '', $rawEndpoint);
        $endpoint = rtrim($endpoint, '/');
        if ($endpoint === '') throw new \RuntimeException('云存储 Endpoint 不能为空');
        $host = strpos($endpoint, $target->bucket . '.') === 0 ? $endpoint : $target->bucket . '.' . $endpoint;
        $path = '/' . $this->encodePath($objectKey);
        return [$scheme . '://' . $host . $path, $host, $path];
    }

    private function ucloudS3Address(ClientStorageTarget $target, string $objectKey): array
    {
        $rawEndpoint = trim((string)$target->endpoint);
        $scheme = stripos($rawEndpoint, 'http://') === 0 ? 'http' : 'https';
        $endpoint = preg_replace('#^https?://#i', '', $rawEndpoint);
        $endpoint = rtrim($endpoint, '/');
        $bucketPrefix = $target->bucket . '.';
        if (stripos($endpoint, $bucketPrefix) === 0) {
            $endpoint = substr($endpoint, strlen($bucketPrefix));
        }
        if (stripos($endpoint, 'internal.') === 0) {
            $base = substr($endpoint, strlen('internal.'));
            if (stripos($base, 's3-') !== 0) $base = 's3-' . $base;
            $endpoint = 'internal.' . $base;
        } elseif (stripos($endpoint, 's3-') !== 0) {
            $endpoint = 's3-' . $endpoint;
        }
        if (!preg_match('/^(?:internal\.)?s3-[a-z0-9-]+\.ufileos\.com(?::[0-9]+)?$/i', $endpoint)) {
            throw new \RuntimeException('无法根据当前 Endpoint 生成 UCloud S3 Endpoint，请填写类似 hk.ufileos.com 或 s3-hk.ufileos.com 的官方地域地址');
        }
        $host = $endpoint;
        $path = '/' . rawurlencode($target->bucket) . '/' . $this->encodePath($objectKey);
        return [$scheme . '://' . $host . $path, $host, $path];
    }

    private function ucloudNativeAddress(ClientStorageTarget $target, string $objectKey): array
    {
        $rawEndpoint = trim((string)$target->endpoint);
        $scheme = stripos($rawEndpoint, 'http://') === 0 ? 'http' : 'https';
        $endpoint = preg_replace('#^https?://#i', '', $rawEndpoint);
        $endpoint = rtrim($endpoint, '/');
        $bucketPrefix = $target->bucket . '.';
        if (stripos($endpoint, $bucketPrefix) === 0) {
            $endpoint = substr($endpoint, strlen($bucketPrefix));
        }
        if (stripos($endpoint, 'internal.s3-') === 0) {
            $endpoint = 'internal.' . substr($endpoint, strlen('internal.s3-'));
        } elseif (stripos($endpoint, 's3-') === 0) {
            $endpoint = substr($endpoint, strlen('s3-'));
        }
        if (!preg_match('/^(?:internal\.)?[a-z0-9-]+\.ufileos\.com(?::[0-9]+)?$/i', $endpoint)) {
            throw new \RuntimeException('UCloud Endpoint 格式不正确，请填写类似 hk.ufileos.com 或 s3-hk.ufileos.com 的官方地域地址');
        }
        $host = $target->bucket . '.' . $endpoint;
        $path = '/' . $this->encodePath($objectKey);
        return [$scheme . '://' . $host . $path, $host, $path];
    }

    private function usesAutomaticPublicUrl(ClientStorageTarget $target): bool
    {
        return rtrim((string)$target->public_url, '/') === rtrim($this->defaultPublicUrlFor($target, $target->object_key), '/');
    }

    private function put(string $url, array $headers, string $body): array
    {
        $response = $this->client->put($url, ['headers' => $headers, 'body' => $body]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $message = trim((string)$response->getBody());
            throw new \RuntimeException('云存储上传失败（HTTP ' . $status . '）：' . mb_substr($message, 0, 500));
        }
        return ['etag' => trim($response->getHeaderLine('ETag'), '"'), 'status' => $status];
    }

    private function delete(string $url, array $headers): void
    {
        $response = $this->client->delete($url, ['headers' => $headers]);
        $status = $response->getStatusCode();
        if (($status < 200 || $status >= 300) && $status !== 404) {
            $message = trim((string)$response->getBody());
            throw new \RuntimeException('测试文件清理失败（HTTP ' . $status . '）：' . mb_substr($message, 0, 500));
        }
    }

    private function encodePath(string $value): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($value, '/'))));
    }

    private function credentialLabels(ClientStorageTarget $target): array
    {
        if ($target->provider === 'tencent_cos') return ['SecretId', 'SecretKey'];
        if ($target->provider === 'ucloud_us3') return ['公钥', '私钥'];
        return ['AccessKey ID', 'AccessKey Secret'];
    }

    private function decrypt(?string $value, string $label): string
    {
        $plain = $this->optionalDecrypt($value);
        if (!$plain) throw new \RuntimeException($label . ' 尚未配置');
        return $plain;
    }

    private function optionalDecrypt(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }
}
