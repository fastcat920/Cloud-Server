<?php

namespace Tests\Unit;

use App\Services\FastCatSubscriptionCipher;
use RuntimeException;
use Tests\TestCase;

class FastCatSubscriptionCipherTest extends TestCase
{
    public function testItEncryptsWithTheActiveKeyAndAuthenticatesTheEnvelope()
    {
        $key = random_bytes(32);
        config([
            'fastcat.subscription.active_kid' => '2026-01',
            'fastcat.subscription.current' => ['kid' => '2026-01', 'key' => base64_encode($key)],
            'fastcat.subscription.next' => ['kid' => '2026-02', 'key' => base64_encode(random_bytes(32))],
        ]);

        $yaml = "proxies:\n  - name: test\n";
        $first = json_decode((new FastCatSubscriptionCipher())->encrypt($yaml), true);
        $second = json_decode((new FastCatSubscriptionCipher())->encrypt($yaml), true);

        $this->assertSame(1, $first['v']);
        $this->assertSame('A256GCM', $first['alg']);
        $this->assertSame('2026-01', $first['kid']);
        $this->assertNotSame($first['nonce'], $second['nonce']);

        $aad = 'fastcat-subscription|v1|' . $first['kid'] . '|' . $first['ts'];
        $plain = openssl_decrypt(
            base64_decode($first['data'], true),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            base64_decode($first['nonce'], true),
            base64_decode($first['tag'], true),
            $aad
        );
        $this->assertSame($yaml, $plain);
    }

    public function testItRejectsAnInvalidOrUnknownActiveKey()
    {
        config([
            'fastcat.subscription.active_kid' => 'missing',
            'fastcat.subscription.current' => ['kid' => '2026-01', 'key' => base64_encode(random_bytes(32))],
            'fastcat.subscription.next' => ['kid' => '', 'key' => ''],
        ]);

        $this->expectException(RuntimeException::class);
        (new FastCatSubscriptionCipher())->encrypt('test');
    }
}
