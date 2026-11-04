<?php

declare(strict_types=1);

namespace CloudDesk;

final class Objects
{
    public function __construct(private Cloud $cloud)
    {
    }
    private function path(string $value): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $value)));
    }
    private function xml(string $body): \SimpleXMLElement
    {
        if (!function_exists('simplexml_load_string')) {
            throw new \RuntimeException('对象存储需要启用 PHP 的 SimpleXML 扩展');
        }
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        if (!$xml) {
            throw new \RuntimeException('对象存储返回格式错误');
        }
        return $xml;
    }
    public function request(array $account, string $method, string $region, string $bucket = '', string $key = '', array $query = [], string $body = '', bool $publicRead = false): array
    {
        if (!preg_match('/^[a-z0-9-]{2,50}$/', $region) || ($bucket !== '' && !preg_match('/^[a-z0-9][a-z0-9.-]{1,250}$/', $bucket))) {
            throw new \RuntimeException('地域或 Bucket 格式错误');
        }
        $credentials = $this->cloud->credentials($account);
        $id = $credentials['key'] ?? '';
        $secret = $credentials['secret'] ?? '';
        $token = $credentials['token'] ?? '';
        $p = $account['provider'];
        $time = gmdate('Ymd\THis\Z');
        $date = substr($time, 0, 8);
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $path = '/'.$this->path($key);
        $host = match($p) {
            'aliyun' => ($bucket ? $bucket.'.' : '').'oss-'.$region.'.aliyuncs.com','tencent' => $bucket ? $bucket.'.cos.'.$region.'.myqcloud.com' : 'service.cos.myqcloud.com','ucloud' => 's3-'.$region.'.ufileos.com',default => throw new \RuntimeException('此账号不支持对象存储')
        };
        $headers = ['Host' => $host];
        if ($method === 'PUT') {
            $headers['content-type'] = 'application/octet-stream';
        }
        if ($p === 'aliyun') {
            $headers['x-oss-date'] = $time;
            $headers['x-oss-content-sha256'] = 'UNSIGNED-PAYLOAD';
            if ($token) {
                $headers['x-oss-security-token'] = $token;
            }
            if ($publicRead && $method === 'PUT') {
                $headers['x-oss-object-acl'] = 'public-read';
            }
            $signed = $headers;
            unset($signed['Host']);
            ksort($signed);
            $canonical = '';
            foreach ($signed as $k => $v) {
                $canonical .= $k.':'.$v."\n";
            }
            $qs = implode('&', array_map(fn ($k) => rawurlencode((string)$k).((string)$query[$k] !== '' ? '='.rawurlencode((string)$query[$k]) : ''), array_keys($query)));
            $scope = $date.'/'.$region.'/oss/aliyun_v4_request';
            $request = $method."\n/".($bucket ? $bucket.'/' : '').$this->path($key)."\n".$qs."\n".$canonical."\n\nUNSIGNED-PAYLOAD";
            $sign = hash_hmac('sha256', $date, 'aliyun_v4'.$secret, true);
            foreach ([$region,'oss','aliyun_v4_request'] as $part) {
                $sign = hash_hmac('sha256', $part, $sign, true);
            }
            $headers['Authorization'] = 'OSS4-HMAC-SHA256 Credential='.$id.'/'.$scope.',Signature='.hash_hmac('sha256', "OSS4-HMAC-SHA256\n$time\n$scope\n".hash('sha256', $request), $sign);
        } elseif ($p === 'tencent') {
            if ($token) {
                $headers['x-cos-security-token'] = $token;
            }
            if ($publicRead && $method === 'PUT') {
                $headers['x-cos-acl'] = 'public-read';
            }
            $signed = [];
            foreach ($headers as $k => $v) {
                $signed[strtolower($k)] = $v;
            }ksort($signed);
            $params = [];
            foreach ($query as $k => $v) {
                $params[strtolower((string)$k)] = $v;
            }ksort($params);
            $period = (time() - 60).';'.(time() + 600);
            $canonical = strtolower($method)."\n/".$key."\n".http_build_query($params, '', '&', PHP_QUERY_RFC3986)."\n".http_build_query($signed, '', '&', PHP_QUERY_RFC3986)."\n";
            $sign = hash_hmac('sha1', $period, $secret);
            $signature = hash_hmac('sha1', "sha1\n$period\n".sha1($canonical)."\n", $sign);
            $headers['Authorization'] = 'q-sign-algorithm=sha1&q-ak='.$id.'&q-sign-time='.$period.'&q-key-time='.$period.'&q-header-list='.implode(';', array_keys($signed)).'&q-url-param-list='.implode(';', array_keys($params)).'&q-signature='.$signature;
        } else {
            $path = '/'.($bucket ? $bucket.'/' : '').$this->path($key);
            $hash = hash('sha256', $body);
            $headers['x-amz-content-sha256'] = $hash;
            $headers['x-amz-date'] = $time;
            if ($token) {
                $headers['x-amz-security-token'] = $token;
            }
            if ($publicRead && $method === 'PUT') {
                $headers['x-amz-acl'] = 'public-read';
            }
            $signed = [];
            foreach ($headers as $k => $v) {
                $signed[strtolower($k)] = $v;
            }ksort($signed);
            $canonical = '';
            foreach ($signed as $k => $v) {
                $canonical .= $k.':'.$v."\n";
            }
            $names = implode(';', array_keys($signed));
            $scope = $date.'/us-east-1/s3/aws4_request';
            $request = "$method\n$path\n$queryString\n$canonical\n$names\n$hash";
            $sign = hash_hmac('sha256', $date, 'AWS4'.$secret, true);
            foreach (['us-east-1','s3','aws4_request'] as $part) {
                $sign = hash_hmac('sha256', $part, $sign, true);
            }
            $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential='.$id.'/'.$scope.', SignedHeaders='.$names.', Signature='.hash_hmac('sha256', "AWS4-HMAC-SHA256\n$time\n$scope\n".hash('sha256', $request), $sign);
        }
        $response = Http::request($method, 'https://'.$host.$path.($queryString ? '?'.$queryString : ''), $headers, $body);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $xml = function_exists('simplexml_load_string') ? @simplexml_load_string(substr($response['body'], 0, 5000), 'SimpleXMLElement', LIBXML_NONET) : false;
            throw new \RuntimeException('存储请求失败（'.$response['status'].'）'.($xml ? '：'.(string)$xml->Code.' '.(string)$xml->Message : ''));
        }
        return $response;
    }
    public function buckets(array $account, string $region, string $marker = ''): array
    {
        $xml = $this->xml($this->request($account, 'GET', $region, query:$account['provider'] === 'aliyun' ? ['marker' => $marker,'max-keys' => 100] : [])['body']);
        $rows = [];
        foreach ($xml->Buckets->Bucket ?? [] as $r) {
            $rows[] = ['name' => (string)$r->Name,'region' => preg_replace('/^oss-/', '', (string)($r->Location ?? $r->Region ?? $region)) ?: $region,'created' => (string)$r->CreationDate];
        }
        return ['items' => $rows,'next' => (string)($xml->NextMarker ?? '')];
    }
    public function list(array $account, string $region, string $bucket, string $prefix = '', string $marker = ''): array
    {
        $xml = $this->xml($this->request($account, 'GET', $region, $bucket, query:['prefix' => $prefix,'marker' => $marker,'delimiter' => '/','max-keys' => 100])['body']);
        $rows = [];
        foreach ($xml->CommonPrefixes ?? [] as $r) {
            $rows[(string)$r->Prefix] = ['key' => (string)$r->Prefix,'directory' => true,'size' => 0,'modified' => ''];
        }
        foreach ($xml->Contents ?? [] as $r) {
            if (!isset($rows[(string)$r->Key])) {
                $rows[(string)$r->Key] = ['key' => (string)$r->Key,'directory' => false,'size' => (int)$r->Size,'modified' => (string)$r->LastModified];
            }
        }
        $rows = array_values($rows);
        $next = (string)($xml->NextMarker ?? '');
        if (!$next && (string)$xml->IsTruncated === 'true' && $rows) {
            $next = end($rows)['key'];
        }
        return ['items' => $rows,'next' => $next];
    }
    public static function publicUrl(string $provider, string $region, string $bucket, string $key): string
    {
        $host = match($provider) {
            'aliyun' => $bucket.'.oss-'.$region.'.aliyuncs.com','tencent' => $bucket.'.cos.'.$region.'.myqcloud.com',default => $bucket.'.'.$region.'.ufileos.com'
        };
        return 'https://'.$host.'/'.implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
