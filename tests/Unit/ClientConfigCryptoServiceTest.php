<?php

namespace Tests\Unit;

use App\Models\ClientConfigSetting;
use App\Models\ClientRemoteConfig;
use App\Services\ClientConfigCryptoService;
use Illuminate\Support\Facades\Crypt;
use ParagonIE_Sodium_Compat as SodiumCompat;
use Tests\TestCase;

class ClientConfigCryptoServiceTest extends TestCase
{
    public function testLegacyXorPayloadCanBeDecoded(): void
    {
        $service = new ClientConfigCryptoService();
        $plain = '{"config_version":"1","domains":["https://api.example.com"]}';
        $key = 'test-xor-key';
        $payload = $service->xorBase64($plain, $key);
        $encrypted = base64_decode($payload, true);
        $decoded = '';
        for ($i = 0; $i < strlen($encrypted); $i++) {
            $decoded .= $encrypted[$i] ^ $key[$i % strlen($key)];
        }
        $this->assertSame($plain, $decoded);
    }

    public function testSignedEnvelopeCoversTheEncryptedPayload(): void
    {
        $service = new ClientConfigCryptoService();
        $pair = SodiumCompat::crypto_sign_keypair();
        $setting = new ClientConfigSetting([
            'encryption_mode' => 'signed_xor_v2',
            'legacy_xor_key_encrypted' => Crypt::encryptString('test-xor-key'),
            'signing_public_key' => base64_encode(SodiumCompat::crypto_sign_publickey($pair)),
            'signing_private_key_encrypted' => Crypt::encryptString(base64_encode(SodiumCompat::crypto_sign_secretkey($pair))),
        ]);
        $config = new ClientRemoteConfig([
            'content_json' => '{"config_version":"1","domains":["https://api.example.com"]}',
            'encryption_mode' => 'signed_xor_v2',
        ]);

        $envelope = json_decode($service->encode($config, $setting), true);

        $this->assertSame(ClientConfigCryptoService::FORMAT_V2, $envelope['_format']);
        $this->assertTrue(SodiumCompat::crypto_sign_verify_detached(
            base64_decode($envelope['signature'], true),
            $envelope['payload'],
            base64_decode($setting->signing_public_key, true)
        ));
    }
}
