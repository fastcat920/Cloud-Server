<?php

namespace App\Services;

use App\Models\Order;
use App\Models\CouponTemplate;
use App\Models\UserCoupon;
use App\Models\ReferralLevel;
use App\Models\ReferralReward;
use App\Models\ReferralSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ReferralProgramService
{
    public const NO_ACTIVE_PLAN_ALLOW_ALL = 'allow_all';
    public const NO_ACTIVE_PLAN_BLOCK_INVITER_COMMISSION = 'block_inviter_commission';
    public const NO_ACTIVE_PLAN_BLOCK_INVITEE_REWARDS = 'block_invitee_rewards';
    public const NO_ACTIVE_PLAN_BLOCK_BOTH = 'block_both';

    private const NO_ACTIVE_PLAN_POLICIES = [
        self::NO_ACTIVE_PLAN_ALLOW_ALL,
        self::NO_ACTIVE_PLAN_BLOCK_INVITER_COMMISSION,
        self::NO_ACTIVE_PLAN_BLOCK_INVITEE_REWARDS,
        self::NO_ACTIVE_PLAN_BLOCK_BOTH,
    ];

    public function hasValidPlan(User $user): bool
    {
        return $user->plan_id !== null
            && ($user->expired_at === null || (int)$user->expired_at > time());
    }

    public function noActivePlanRewardPolicy(): string
    {
        $setting = $this->setting();
        if (!$setting || !$setting->enabled
            || !Schema::hasColumn('v2_referral_setting', 'no_active_plan_reward_policy')) {
            return self::NO_ACTIVE_PLAN_ALLOW_ALL;
        }
        $policy = (string)$setting->no_active_plan_reward_policy;
        return in_array($policy, self::NO_ACTIVE_PLAN_POLICIES, true)
            ? $policy
            : self::NO_ACTIVE_PLAN_ALLOW_ALL;
    }

    public function snapshotReferralEligibility(User $invitee, ?User $inviter = null): void
    {
        if (!Schema::hasColumn('v2_user', 'invite_commission_eligible')
            || !Schema::hasColumn('v2_user', 'invitee_reward_eligible')
            || !Schema::hasColumn('v2_user', 'invite_reward_evaluated_at')) return;

        if (!$invitee->invite_user_id) {
            $invitee->invite_commission_eligible = null;
            $invitee->invitee_reward_eligible = null;
            $invitee->invite_reward_evaluated_at = null;
            return;
        }

        $inviter = $inviter ?: User::find($invitee->invite_user_id);
        $eligibility = $inviter
            ? $this->referralEligibilityFor($inviter, $this->noActivePlanRewardPolicy())
            : ['invite_commission_eligible' => true, 'invitee_reward_eligible' => true];
        $invitee->invite_commission_eligible = $eligibility['invite_commission_eligible'];
        $invitee->invitee_reward_eligible = $eligibility['invitee_reward_eligible'];
        $invitee->invite_reward_evaluated_at = time();
    }

    public function referralEligibilityFor(User $inviter, string $policy): array
    {
        if (!in_array($policy, self::NO_ACTIVE_PLAN_POLICIES, true)) {
            $policy = self::NO_ACTIVE_PLAN_ALLOW_ALL;
        }
        if ($this->hasValidPlan($inviter) || $policy === self::NO_ACTIVE_PLAN_ALLOW_ALL) {
            return ['invite_commission_eligible' => true, 'invitee_reward_eligible' => true];
        }
        return [
            'invite_commission_eligible' => !in_array($policy, [
                self::NO_ACTIVE_PLAN_BLOCK_INVITER_COMMISSION,
                self::NO_ACTIVE_PLAN_BLOCK_BOTH,
            ], true),
            'invitee_reward_eligible' => !in_array($policy, [
                self::NO_ACTIVE_PLAN_BLOCK_INVITEE_REWARDS,
                self::NO_ACTIVE_PLAN_BLOCK_BOTH,
            ], true),
        ];
    }

    public function inviterCommissionEligible(User $invitee): bool
    {
        if (!Schema::hasColumn('v2_user', 'invite_commission_eligible')) return true;
        return $invitee->invite_commission_eligible === null
            ? true
            : (bool)$invitee->invite_commission_eligible;
    }

    public function inviteeRewardEligible(User $invitee): bool
    {
        if (!Schema::hasColumn('v2_user', 'invitee_reward_eligible')) return true;
        return $invitee->invitee_reward_eligible === null
            ? true
            : (bool)$invitee->invitee_reward_eligible;
    }

    public function rewardRestrictionForInviter(User $inviter): ?array
    {
        $policy = $this->noActivePlanRewardPolicy();
        if ($policy === self::NO_ACTIVE_PLAN_ALLOW_ALL || $this->hasValidPlan($inviter)) return null;
        return ['policy' => $policy];
    }

    public function assignInitialLevel(User $user): ?ReferralLevel
    {
        if ($user->referral_level_id || !Schema::hasTable('v2_referral_level')
            || !Schema::hasColumn('v2_referral_level', 'required_revenue')
            || !Schema::hasColumn('v2_user', 'referral_level_id')) return null;

        $level = ReferralLevel::where('enabled', 1)
            ->where('required_invites', 0)
            ->where('required_revenue', 0)
            ->orderBy('sort', 'DESC')->orderBy('id', 'DESC')->first();
        if (!$level) return null;

        $user->referral_level_id = $level->id;
        $user->referral_level_expires_at = null;
        $user->commission_rate = $level->commission_rate;
        $user->save();
        return $level;
    }

    public function memberDiscountRate(User $user): int
    {
        $manual = max(0, min(100, (int)$user->discount));
        if (!$user->referral_level_id) $this->assignInitialLevel($user);
        if (!$user->referral_level_id) return $manual;
        if ($user->referral_level_expires_at && $user->referral_level_expires_at <= time()) return $manual;
        $level = ReferralLevel::where('id', $user->referral_level_id)->where('enabled', 1)->first();
        return max($manual, $level ? max(0, min(100, (int)$level->member_discount)) : 0);
    }

    public function issueNewcomerCoupon(User $user): ?UserCoupon
    {
        if (!$user->invite_user_id || !Schema::hasTable('v2_user_coupon')) return null;
        if (!$this->inviteeRewardEligible($user)) return null;
        $setting = $this->setting();
        if (!$setting || !$setting->enabled || !$setting->newcomer_coupon_template_id) return null;
        $template = CouponTemplate::find($setting->newcomer_coupon_template_id);
        if (!$template) return null;
        return app(CouponWalletService::class)->issue($template, $user, 'referral_newcomer', 'inviter:' . $user->invite_user_id);
    }

    public function reverseOrderRewards(int $orderId, string $reason = ''): int
    {
        if (!Schema::hasTable('v2_referral_reward')) return 0;

        $count = DB::transaction(function () use ($orderId, $reason) {
            $rewards = ReferralReward::where('order_id', $orderId)
                ->where('status', 'granted')
                ->lockForUpdate()
                ->get();
            if ($rewards->isEmpty()) return 0;

            foreach ($rewards as $reward) {
                if (in_array($reward->reward_type, ['balance', 'commission_balance'], true) && $reward->reward_value > 0) {
                    $user = User::where('id', $reward->user_id)->lockForUpdate()->first();
                    if (!$user) throw new \RuntimeException('奖励用户不存在，无法撤销');
                    $field = $reward->reward_type === 'commission_balance' ? 'commission_balance' : 'balance';
                    if ((int)$user->{$field} < (int)$reward->reward_value) {
                        throw new \RuntimeException('用户可用余额不足，无法自动撤销奖励');
                    }
                    $commissionBefore = (int)$user->commission_balance;
                    $walletBefore = (int)$user->balance;
                    $user->{$field} -= (int)$reward->reward_value;
                    $user->save();
                    if ($reward->reward_type === 'commission_balance') {
                        app(CommissionLedgerService::class)->record([
                            'user_id' => $user->id,
                            'type' => 'commission_reversal',
                            'amount' => -(int)$reward->reward_value,
                            'balance_before' => $commissionBefore,
                            'balance_after' => (int)$user->commission_balance,
                            'source_key' => 'referral_reward_reverse:' . $reward->id,
                            'source_type' => 'referral_reward',
                            'source_id' => $reward->id,
                            'order_id' => $reward->order_id,
                            'description' => $reason ?: '邀请奖励撤销',
                        ]);
                    } else {
                        app(BalanceLedgerService::class)->record([
                            'user_id' => $user->id,
                            'type' => 'referral_reversal',
                            'amount' => -(int)$reward->reward_value,
                            'balance_before' => $walletBefore,
                            'balance_after' => (int)$user->balance,
                            'source_key' => 'referral_reward_reverse:' . $reward->id,
                            'source_type' => 'referral_reward',
                            'source_id' => $reward->id,
                            'order_id' => $reward->order_id,
                            'description' => $reason ?: '邀请奖励撤销',
                        ]);
                    }
                }
                if (in_array($reward->reward_type, ['traffic', 'duration'], true) && $reward->reward_value > 0) {
                    $user = User::where('id', $reward->user_id)->lockForUpdate()->first();
                    if (!$user) throw new \RuntimeException('奖励用户不存在，无法撤销');
                    if ($reward->reward_type === 'traffic') {
                        $bytes = (int)$reward->reward_value * 1073741824;
                        if ((int)$user->transfer_enable - $bytes < (int)$user->u + (int)$user->d) throw new \RuntimeException('奖励流量已被使用，无法自动撤销');
                        $user->transfer_enable -= $bytes;
                    } else {
                        $seconds = (int)$reward->reward_value * 86400;
                        if ((int)$user->expired_at - $seconds < time()) throw new \RuntimeException('奖励时长已被使用，无法自动撤销');
                        $user->expired_at -= $seconds;
                    }
                    $user->save();
                }
                $reward->status = 'reversed';
                $reward->description = trim(($reward->description ?: '') . ($reason ? '；撤销原因：' . $reason : '；管理员撤销'));
                $reward->save();
            }

            foreach ($rewards->where('reward_type', 'level')->pluck('user_id')->unique() as $userId) {
                $user = User::where('id', $userId)->lockForUpdate()->first();
                if (!$user) continue;
                $remainingRate = (int)ReferralReward::where('user_id', $userId)
                    ->where('reward_type', 'level')->where('status', 'granted')->max('reward_value');
                $setting = $this->setting();
                $user->commission_rate = $remainingRate ?: (int)($setting ? $setting->base_commission_rate : config('v2board.invite_commission', 10));
                $user->save();
            }

            return $rewards->count();
        });
        Cache::forget('admin_referral_dashboard');
        return $count;
    }

    public function setting()
    {
        if (!Schema::hasTable('v2_referral_setting')) return null;
        return ReferralSetting::current();
    }

    public function commissionRate(User $inviter): int
    {
        $setting = $this->setting();
        if ($setting && $setting->enabled) {
            if (!$inviter->referral_level_id) $this->assignInitialLevel($inviter);
            if ($inviter->referral_level_id && (!$inviter->referral_level_expires_at || $inviter->referral_level_expires_at > time())) {
                $level = ReferralLevel::where('id', $inviter->referral_level_id)->where('enabled', 1)->first();
                if ($level) return (int)$level->commission_rate;
            }
            return (int)$setting->base_commission_rate;
        }

        return $inviter->commission_rate
            ? (int)$inviter->commission_rate
            : (int)config('v2board.invite_commission', 10);
    }

    public function processCompletedOrder(Order $order): void
    {
        if (!$order->invite_user_id || (int)$order->type !== 1 || (int)$order->status !== 3) return;
        $setting = $this->setting();
        if (!$setting || !$setting->enabled) return;
        if ($order->total_amount < $setting->first_order_min) return;
        if (!Schema::hasTable('v2_referral_reward')) return;

        DB::transaction(function () use ($order, $setting) {
            $effectiveKey = 'effective_invite:' . $order->user_id;
            $effectiveReward = ReferralReward::where('event_key', $effectiveKey)->lockForUpdate()->first();
            if ($effectiveReward && $effectiveReward->status !== 'reversed') return;
            $effectiveData = [
                'user_id' => $order->invite_user_id,
                'invited_user_id' => $order->user_id,
                'order_id' => $order->id,
                'reward_type' => 'effective_invite',
                'status' => 'granted',
                'description' => '好友完成首笔有效订单',
                'granted_at' => time(),
            ];
            if ($effectiveReward) $effectiveReward->fill($effectiveData)->save();
            else ReferralReward::create(array_merge(['event_key' => $effectiveKey], $effectiveData));

            $invitee = User::find($order->user_id);
            if ($setting->invitee_reward > 0 && $invitee && $this->inviteeRewardEligible($invitee)) {
                $this->grantMoney(
                    $invitee,
                    'balance',
                    (int)$setting->invitee_reward,
                    'invitee_first_order:' . $order->id,
                    $order,
                    '受邀用户首单奖励'
                );
            }

            $effectiveCount = ReferralReward::where('user_id', $order->invite_user_id)
                ->where('reward_type', 'effective_invite')
                ->where('status', 'granted')
                ->count();
            $referralRevenue = (int)Order::where('invite_user_id', $order->invite_user_id)
                ->where('status', 3)->sum('total_amount');
            $this->grantLevelRewards($order, $effectiveCount, $referralRevenue, $setting);
            $this->upgradeLevel($order->invite_user_id, $effectiveCount, $referralRevenue, $order);
        });
        Cache::forget('admin_referral_dashboard');
    }

    private function grantLevelRewards(Order $order, int $effectiveCount, int $referralRevenue, ReferralSetting $setting): void
    {
        if (!Schema::hasColumn('v2_referral_milestone', 'referral_level_id')) return;

        $levels = ReferralLevel::with(['reward' => function ($query) {
                $query->where('enabled', 1);
            }])
            ->where('enabled', 1)
            ->where('required_invites', '<=', $effectiveCount)
            ->where('required_revenue', '<=', $referralRevenue)
            ->orderBy('sort')->orderBy('id')->get();
        $user = User::find($order->invite_user_id);
        foreach ($levels as $level) {
            $reward = $level->reward;
            if (!$reward) continue;
            $eventKey = 'milestone:' . $reward->id . ':' . $order->invite_user_id;
            $description = '成长等级达标奖励：' . $level->name;
            if (in_array($reward->reward_type, ['balance', 'commission_balance'], true)) {
                if (!$this->withinMonthlyLimit($order->invite_user_id, $reward->reward_value, $setting)) continue;
                $this->grantMoney($user, $reward->reward_type, (int)$reward->reward_value, $eventKey, $order, $description);
            } else {
                $this->grantEntitlement($user, $reward->reward_type, (int)$reward->reward_value, $eventKey, $order, $description);
            }
        }
    }

    private function upgradeLevel(int $userId, int $effectiveCount, int $revenue, Order $order): void
    {
        $level = ReferralLevel::where('enabled', 1)
            ->where('required_invites', '<=', $effectiveCount)
            ->where('required_revenue', '<=', $revenue)
            ->orderBy('sort', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();
        if (!$level) return;
        $user = User::find($userId);
        if (!$user || (int)$user->referral_level_id === (int)$level->id) return;
        if ($user->referral_level_id) {
            $current = ReferralLevel::find($user->referral_level_id);
            if ($current && (int)$current->sort >= (int)$level->sort) return;
        }
        $user->commission_rate = $level->commission_rate;
        $user->referral_level_id = $level->id;
        $user->referral_level_expires_at = $level->valid_days ? time() + (int)$level->valid_days * 86400 : null;
        $user->save();
        ReferralReward::updateOrCreate(['event_key' => 'level:' . $level->id . ':' . $userId], [
            'user_id' => $userId,
            'invited_user_id' => $order->user_id,
            'order_id' => $order->id,
            'reward_type' => 'level',
            'reward_value' => $level->commission_rate,
            'status' => 'granted',
            'description' => '成长等级升级：' . $level->name,
            'granted_at' => time(),
        ]);
    }

    private function grantMoney($user, string $type, int $amount, string $eventKey, Order $order, string $description): void
    {
        if (!$user || $amount <= 0) return;
        $existing = ReferralReward::where('event_key', $eventKey)->lockForUpdate()->first();
        if ($existing && $existing->status !== 'reversed') return;
        $commissionBefore = (int)$user->commission_balance;
        $walletBefore = (int)$user->balance;
        if ($type === 'commission_balance') $user->commission_balance += $amount;
        else $user->balance += $amount;
        $user->save();
        $data = [
            'user_id' => $user->id,
            'invited_user_id' => $order->user_id,
            'order_id' => $order->id,
            'reward_type' => $type,
            'reward_value' => $amount,
            'status' => 'granted',
            'description' => $description,
            'granted_at' => time(),
        ];
        if ($existing) {
            $existing->fill($data)->save();
            $reward = $existing;
        } else {
            $reward = ReferralReward::create(array_merge(['event_key' => $eventKey], $data));
        }
        if ($type === 'commission_balance') {
            app(CommissionLedgerService::class)->record([
                'user_id' => $user->id,
                'type' => 'reward_income',
                'amount' => $amount,
                'balance_before' => $commissionBefore,
                'balance_after' => (int)$user->commission_balance,
                'source_key' => 'referral_reward:' . $reward->id,
                'source_type' => 'referral_reward',
                'source_id' => $reward->id,
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'description' => $description,
            ]);
        } else {
            app(BalanceLedgerService::class)->record([
                'user_id' => $user->id,
                'type' => 'referral_reward',
                'amount' => $amount,
                'balance_before' => $walletBefore,
                'balance_after' => (int)$user->balance,
                'source_key' => 'referral_reward:' . $reward->id,
                'source_type' => 'referral_reward',
                'source_id' => $reward->id,
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'description' => $description,
            ]);
        }
    }

    private function grantEntitlement($user, string $type, int $value, string $eventKey, Order $order, string $description): void
    {
        if (!$user || $value <= 0) return;
        $existing = ReferralReward::where('event_key', $eventKey)->lockForUpdate()->first();
        if ($existing && $existing->status !== 'reversed') return;
        if ($type === 'traffic') $user->transfer_enable += $value * 1073741824;
        elseif ($type === 'duration') $user->expired_at = max((int)$user->expired_at, time()) + $value * 86400;
        else return;
        $user->save();
        $data = ['user_id'=>$user->id,'invited_user_id'=>$order->user_id,'order_id'=>$order->id,'reward_type'=>$type,'reward_value'=>$value,'status'=>'granted','description'=>$description,'granted_at'=>time()];
        if ($existing) $existing->fill($data)->save();
        else ReferralReward::create(array_merge(['event_key'=>$eventKey], $data));
    }

    private function withinMonthlyLimit(int $userId, int $amount, ReferralSetting $setting): bool
    {
        if (!$setting->monthly_reward_limit) return true;
        $granted = ReferralReward::where('user_id', $userId)
            ->whereIn('reward_type', ['balance', 'commission_balance'])
            ->where('status', 'granted')
            ->where('created_at', '>=', strtotime(date('Y-m-1')))
            ->sum('reward_value');
        return $granted + $amount <= $setting->monthly_reward_limit;
    }
}
