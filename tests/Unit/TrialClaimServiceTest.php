<?php

namespace Tests\Unit;

use App\Services\TrialClaimService;
use RuntimeException;
use Tests\TestCase;

class TrialClaimServiceTest extends TestCase
{
    public function testItNormalizesEmailBeforeHashing()
    {
        config(['account.trial_identity_key' => str_repeat('a', 32)]);
        $service = new TrialClaimService();

        $this->assertSame(
            $service->hashEmail('User@Example.com'),
            $service->hashEmail('  user@example.com  ')
        );
    }

    public function testItRejectsAMissingOrShortIdentityKey()
    {
        config(['account.trial_identity_key' => 'short']);

        $this->expectException(RuntimeException::class);
        (new TrialClaimService())->hashEmail('user@example.com');
    }
}
