<?php

namespace App\Services;

use App\Models\ClientConfigSetting;
use App\Models\ClientRemoteConfig;
use Illuminate\Support\Facades\Crypt;
use ParagonIE_Sodium_Compat as SodiumCompat;

class ClientConfigCryptoService
{
    public const FORMAT_V2 = 'fastcat-config-v2';

    public function canonicalJson(array $content): string
    {
        $this->sortRecursively($content);
        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) throw new \RuntimeException('客户端配置无法序列化为 JSON');
        return $json;
    }

    public function checksum(array $content): string
    {
        return hash('sha256', $this->canonicalJson($content));
    }

    public function encode(ClientRemoteConfig $config, ?ClientConfigSetting $setting = null): string
    {
        $setting = $setting ?: ClientConfigSetting::current();
        $plain = $this->canonicalJson($config->content);
        $mode = $config->encryption_mode ?: $setting->encryption_mode;
        if ($mode === 'plain') return $plain;

        $key = $this->decryptRequired($setting->legacy_xor_key_encrypted, '请先在客户端配置中心设置 XOR 密钥');
        $payload = $this->xorBase64($plain, $key);
        if ($mode === 'xor_base64') return $payload;
        if ($mode !== 'signed_xor_v2') throw new \RuntimeException('不支持的配置加密模式');

        $privateKey = $this->decryptRequired($setting->signing_private_key_encrypted, '请先生成配置签名密钥');
        $signature = SodiumCompat::crypto_sign_detached($payload, base64_decode($privateKey, true));
        return json_encode([
            '_format' => self::FORMAT_V2,
            'algorithm' => 'Ed25519',
            'encoding' => 'xor+base64',
            'payload' => $payload,
            'signature' => base64_encode($signature),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function xorBase64(string $plain, string $key): string
    {
        if ($key === '') throw new \InvalidArgumentException('XOR 密钥不能为空');
        $output = '';
        $keyLength = strlen($key);
        for ($i = 0, $length = strlen($plain); $i < $length; $i++) {
            $output .= $plain[$i] ^ $key[$i % $keyLength];
        }
        return base64_encode($output);
    }

    public function ensureSigningKeyPair(ClientConfigSetting $setting): string
    {
        if ($setting->signing_public_key && $setting->signing_private_key_encrypted) {
            return $setting->signing_public_key;
        }
        $pair = SodiumCompat::crypto_sign_keypair();
        $public = base64_encode(SodiumCompat::crypto_sign_publickey($pair));
        $private = base64_encode(SodiumCompat::crypto_sign_secretkey($pair));
        $setting->signing_public_key = $public;
        $setting->signing_private_key_encrypted = Crypt::encryptString($private);
        $setting->save();
        return $public;
    }

    private function decryptRequired(?string $encrypted, string $message): string
    {
        if (!$encrypted) throw new \RuntimeException($message);
        $value = Crypt::decryptString($encrypted);
        if ($value === '') throw new \RuntimeException($message);
        return $value;
    }

    private function sortRecursively(array &$value): void
    {
        if ($this->isAssoc($value)) ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) $this->sortRecursively($item);
        }
        unset($item);
    }

    private function isAssoc(array $value): bool
    {
        if ($value === []) return false;
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
