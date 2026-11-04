<?php

namespace App\Services;

use App\Models\CloudflareDnsAccount;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class CloudflareDnsService
{
    const ENDPOINT = 'https://api.cloudflare.com/client/v4';

    private $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => self::ENDPOINT . '/',
            'timeout' => 15,
            'connect_timeout' => 8,
            'http_errors' => false,
        ]);
    }

    public function verifyToken(CloudflareDnsAccount $account)
    {
        return $this->request($account, 'GET', '/user/tokens/verify');
    }

    public static function normalizeToken($token)
    {
        $token = trim((string)$token, " \t\n\r\0\x0B\"'");
        $token = preg_replace('/^(?:Authorization\s*:\s*)?Bearer\s+/i', '', $token);
        return preg_replace('/\s+/', '', trim((string)$token));
    }

    public function listZones(CloudflareDnsAccount $account)
    {
        $items = [];
        $page = 1;
        do {
            $response = $this->request($account, 'GET', '/zones', [
                'query' => ['page' => $page, 'per_page' => 50, 'order' => 'name', 'direction' => 'asc'],
            ]);
            $rows = isset($response['result']) && is_array($response['result']) ? $response['result'] : [];
            $items = array_merge($items, $rows);
            $totalPages = (int)($response['result_info']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $items;
    }

    public function listRecords(CloudflareDnsAccount $account, $zoneId, array $query)
    {
        return $this->request($account, 'GET', '/zones/' . rawurlencode($zoneId) . '/dns_records', ['query' => $query]);
    }

    public function createRecord(CloudflareDnsAccount $account, $zoneId, array $payload)
    {
        return $this->request($account, 'POST', '/zones/' . rawurlencode($zoneId) . '/dns_records', ['json' => $payload]);
    }

    public function updateRecord(CloudflareDnsAccount $account, $zoneId, $recordId, array $payload)
    {
        return $this->request($account, 'PATCH', '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId), ['json' => $payload]);
    }

    public function deleteRecord(CloudflareDnsAccount $account, $zoneId, $recordId)
    {
        return $this->request($account, 'DELETE', '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId));
    }

    private function request(CloudflareDnsAccount $account, $method, $path, array $options = [])
    {
        try {
            $token = self::normalizeToken(Crypt::decryptString($account->api_token_encrypted));
        } catch (\Throwable $error) {
            throw new RuntimeException('API Token 无法解密，请重新填写');
        }
        if ($token === '') {
            throw new RuntimeException('API Token 为空，请重新填写');
        }
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ]);
        if (array_key_exists('json', $options)) {
            $options['headers']['Content-Type'] = 'application/json';
        }
        try {
            $response = $this->client->request($method, ltrim($path, '/'), $options);
            $status = $response->getStatusCode();
            $decoded = json_decode((string)$response->getBody(), true);
        } catch (\Throwable $error) {
            throw new RuntimeException('Cloudflare 连接失败：' . $error->getMessage());
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Cloudflare 返回了无法识别的数据（HTTP ' . $status . '）');
        }
        if ($status < 200 || $status >= 300 || empty($decoded['success'])) {
            $messages = [];
            foreach (($decoded['errors'] ?? []) as $error) {
                if (!empty($error['message'])) $messages[] = $error['message'];
                foreach (($error['error_chain'] ?? []) as $nestedError) {
                    if (!empty($nestedError['message'])) $messages[] = $nestedError['message'];
                }
            }
            $errorCodes = [];
            foreach (($decoded['errors'] ?? []) as $error) {
                if (isset($error['code'])) $errorCodes[] = (int)$error['code'];
                foreach (($error['error_chain'] ?? []) as $nestedError) {
                    if (isset($nestedError['code'])) $errorCodes[] = (int)$nestedError['code'];
                }
            }
            if (in_array(6003, $errorCodes, true) || in_array(6111, $errorCodes, true)) {
                $messages = ['API Token 格式无效，请填写 Cloudflare API Token 原文，不要填写 Bearer 前缀或 Global API Key'];
            }
            throw new RuntimeException('Cloudflare 请求失败（HTTP ' . $status . '）：' . ($messages ? implode('；', $messages) : '请检查 Token 权限和请求参数'));
        }
        return $decoded;
    }
}
