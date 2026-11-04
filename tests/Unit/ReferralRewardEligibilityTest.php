<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ReferralProgramService;
use PHPUnit\Framework\TestCase;

class ReferralRewardEligibilityTest extends TestCase
{
    public function testPlanValidityOnlyDependsOnPlanAndExpiry(): void
    {
        $service = new ReferralProgramService();

        $this->assertFalse($service->hasValidPlan(new User(['plan_id' => null, 'expired_at' => null])));
        $this->assertFalse($service->hasValidPlan(new User(['plan_id' => 1, 'expired_at' => time() - 1])));
        $this->assertTrue($service->hasValidPlan(new User(['plan_id' => 1, 'expired_at' => time() + 3600])));
        $this->assertTrue($service->hasValidPlan(new User(['plan_id' => 1, 'expired_at' => null])));
    }

    public function testNoActivePlanPoliciesOnlyBlockConfiguredDirectRewards(): void
    {
        $service = new ReferralProgramService();
        $inviter = new User(['plan_id' => null, 'expired_at' => null]);

        $this->assertSame(
            ['invite_commission_eligible' => false, 'invitee_reward_eligible' => true],
            $service->referralEligibilityFor($inviter, ReferralProgramService::NO_ACTIVE_PLAN_BLOCK_INVITER_COMMISSION)
        );
        $this->assertSame(
            ['invite_commission_eligible' => true, 'invitee_reward_eligible' => false],
            $service->referralEligibilityFor($inviter, ReferralProgramService::NO_ACTIVE_PLAN_BLOCK_INVITEE_REWARDS)
        );
        $this->assertSame(
            ['invite_commission_eligible' => false, 'invitee_reward_eligible' => false],
            $service->referralEligibilityFor($inviter, ReferralProgramService::NO_ACTIVE_PLAN_BLOCK_BOTH)
        );
    }

    public function testActivePlanAlwaysKeepsBothRewardsEligible(): void
    {
        $service = new ReferralProgramService();
        $inviter = new User(['plan_id' => 1, 'expired_at' => time() + 3600]);

        $this->assertSame(
            ['invite_commission_eligible' => true, 'invitee_reward_eligible' => true],
            $service->referralEligibilityFor($inviter, ReferralProgramService::NO_ACTIVE_PLAN_BLOCK_BOTH)
        );
    }
}
