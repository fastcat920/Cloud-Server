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
        return json_decode(file_get_contents(__DIR__.'/client-defaults.json'), true, 32, JSON_THROW_ON_ERROR);
    }
    public static function canonical(array|object $content, array $orders = [], bool $preserveOrder = false): string
    {
        $sort = function (mixed $value, array $path = []) use (&$sort, $orders, $preserveOrder): mixed {
            if (!is_array($value) && !is_object($value)) {
                return $value;
            }
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $index => &$item) {
                    $item = $sort($item, [...$path, (string)$index]);
                }
                return $value;
            }
            $values = (array)$value;
            if (!$preserveOrder) {
                ksort($values);
            }
            $encoded = json_encode($path, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $preferred = $orders[$encoded] ?? [];
            $keys = array_unique(array_merge($preferred, array_keys($values)));
            $result = new \stdClass();
            foreach ($keys as $key) {
                if (array_key_exists($key, $values)) {
                    $result->{(string)$key} = $sort($values[$key], [...$path, (string)$key]);
                }
            }
            return $result;
        };
        return json_encode($sort($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
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
        $plain = self::canonical(self::materialize($version['content'], $version['schema'] ?? []), $version['field_order'] ?? [], true);
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
                if (($row['source'] ?? 'direct') !== 'app_store') {
                    $artifacts = $row['artifacts'] ?? null;
                    $downloads = $artifacts === null ? [['url' => $row['url'] ?? '']] : $artifacts;
                    if (!is_array($downloads) || !$downloads) {
                        throw new \RuntimeException($platform.' 缺少安装包');
                    }
                    foreach ($downloads as $artifact) {
                        $url = is_array($artifact) ? ($artifact['url'] ?? '') : '';
                        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true)) {
                            throw new \RuntimeException($platform.' 下载地址无效');
                        }
                    }
                }
            }
        }
    }
}
