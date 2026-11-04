<?php

declare(strict_types=1);

namespace CloudDesk;

final class Configs
{
    public function __construct(private Security $security)
    {
    }
    public static function defaults(): array
    {
        return ['config_version' => '1','panel_type' => 'v2board','api_prefix' => '/api/v1','domains' => [],'gateway_urls' => [],'contact' => ['website' => []],'features' => array_fill_keys(['balance_enabled','devices_enabled','gift_card_enabled','join_group_enabled','knowledge_base_enabled','orders_enabled','tickets_enabled','traffic_details_enabled'], true),'update' => ['schema_version' => 2,'platforms' => new \stdClass()],'latency' => ['display_discount_percent' => 0]];
    }
    public static function canonical(array|object $content): string
    {
        $sort = function (&$value) use (&$sort) {
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    ksort($value);
                }
                foreach ($value as &$item) {
                    $sort($item);
                }
            }
        };
        $sort($content);
        return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
    public static function materialize(mixed $value, array $schema): mixed
    {
        if (($schema['type'] ?? null) === 'object' && is_array($value)) {
            if ($value === []) {
                return new \stdClass();
            }
            foreach ($value as $key => &$child) {
                $child = self::materialize($child, $schema['properties'][$key] ?? []);
            }
            unset($child);
        } elseif (($schema['type'] ?? null) === 'array' && is_array($value)) {
            foreach ($value as &$child) {
                $child = self::materialize($child, $schema['items'] ?? []);
            }
        }
        return $value;
    }
    public function encode(array $version, array $settings): string
    {
        $plain = self::canonical(self::materialize($version['content'], $version['schema'] ?? []));
        $mode = $version['mode'];
        if ($mode === 'plain') {
            return $plain;
        }
        $key = $this->security->decrypt($settings['xor_key'] ?? null);
        if ($key === '') {
            throw new \RuntimeException('请先设置 XOR 密钥');
        }
        $cipher = '';
        for ($i = 0,$length = strlen($plain);$i < $length;$i++) {
            $cipher .= $plain[$i] ^ $key[$i % strlen($key)];
        }
        $payload = base64_encode($cipher);
        if ($mode === 'xor_base64') {
            return $payload;
        }
        if ($mode !== 'signed_xor_v2') {
            throw new \RuntimeException('加密模式无效');
        }
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new \RuntimeException('签名模式需要 PHP sodium 扩展');
        }
        $secret = base64_decode($this->security->decrypt($settings['signing_secret'] ?? null), true);
        if (!$secret || strlen($secret) !== 64) {
            throw new \RuntimeException('请先生成签名密钥');
        }
        return json_encode(['_format' => 'fastcat-config-v2','algorithm' => 'Ed25519','encoding' => 'xor+base64','payload' => $payload,'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secret))], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
    public static function validate(array $content): void
    {
        foreach (array_merge($content['domains'] ?? [], $content['gateway_urls'] ?? [], $content['contact']['website'] ?? []) as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true)) {
                throw new \RuntimeException('配置中的网络地址必须是 HTTP 或 HTTPS 地址');
            }
        }
        foreach ($content['update']['platforms'] ?? [] as $platform => $row) {
            if ($row['enabled'] ?? false) {
                if (empty($row['latest_version'])) {
                    throw new \RuntimeException($platform.' 缺少最新版本号');
                }
                if (($row['source'] ?? 'direct') === 'app_store' && empty($row['app_id'])) {
                    throw new \RuntimeException($platform.' 缺少 App Store ID');
                }
                if (($row['source'] ?? 'direct') !== 'app_store' && (!filter_var($row['url'] ?? '', FILTER_VALIDATE_URL) || !in_array(parse_url($row['url'], PHP_URL_SCHEME), ['http','https'], true))) {
                    throw new \RuntimeException($platform.' 下载地址无效');
                }
            }
        }
    }
}
