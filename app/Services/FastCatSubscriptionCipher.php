<?php

namespace App\Services;

use RuntimeException;

class FastCatSubscriptionCipher
{
    public const VERSION = 1;
    public const ALGORITHM = 'A256GCM';

    public function encrypt(string $plaintext): string
    {
        $activeKid = (string) config('fastcat.subscription.active_kid', '');
        $key = $this->activeKey($activeKid);
        $timestamp = time();
        $nonce = random_bytes(12);
        $tag = '';
        $aad = $this->aad($activeKid, $timestamp);
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('FastCat subscription encryption failed.');
        }

        $json = json_encode([
            'v' => self::VERSION,
            'alg' => self::ALGORITHM,
            'kid' => $activeKid,
            'ts' => $timestamp,
            'nonce' => base64_encode($nonce),
            'data' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ], JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('FastCat subscription envelope encoding failed.');
        }
        return $json;
    }

    private function activeKey(string $activeKid): string
    {
        if ($activeKid === '') {
            throw new RuntimeException('FASTCAT_ACTIVE_KID is not configured.');
        }

        foreach (['current', 'next'] as $slot) {
            $kid = (string) config("fastcat.subscription.{$slot}.kid", '');
            if ($kid !== $activeKid) {
                continue;
            }
            $encoded = (string) config("fastcat.subscription.{$slot}.key", '');
            $key = base64_decode($encoded, true);
            if ($key === false || strlen($key) !== 32) {
                throw new RuntimeException("FastCat key '{$activeKid}' must be Base64-encoded 32 bytes.");
            }
            return $key;
        }

        throw new RuntimeException("FASTCAT_ACTIVE_KID does not match a configured key slot.");
    }

    private function aad(string $kid, int $timestamp): string
    {
        return 'fastcat-subscription|v1|' . $kid . '|' . $timestamp;
    }
}
