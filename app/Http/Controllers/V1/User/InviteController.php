<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\CommissionLedger;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Models\ReferralLevel;
use App\Models\ReferralReward;
use App\Models\ReferralSetting;
use App\Models\UserCoupon;
use App\Services\ReferralProgramService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InviteController extends Controller
{
    public function save(Request $request)
    {
        if (InviteCode::where('user_id', $request->user['id'])->where('status', 0)->count() >= config('v2board.invite_gen_limit', 5)) {
            abort(500, __('The maximum number of creations has been reached'));
        }
        $inviteCode = new InviteCode();
        $inviteCode->user_id = $request->user['id'];
        $inviteCode->code = Helper::randomChar(8);
        return response([
            'data' => $inviteCode->save()
        ]);
    }

    public function details(Request $request)
    {
        $current = max((int)$request->input('current', 1), 1);
        $pageSize = min(max((int)$request->input('page_size', 10), 10), 100);
        // Keep the default response compatible with legacy clients. The
        // unified ledger is opt-in and also has its own endpoint.
        $wantsLedger = $request->input('format') === 'ledger'
            || $request->attributes->get('invite_ledger') === true;
        if ($wantsLedger && Schema::hasTable('v2_commission_ledger')) {
            $builder = CommissionLedger::where('user_id', $request->user['id'])->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
            $type = $request->input('type');
            if ($type === 'income') $builder->whereIn('type', ['commission_income', 'reward_income']);
            elseif ($type === 'transfer') $builder->where('type', 'transfer_out');
            elseif ($type === 'withdrawal') $builder->whereIn('type', ['withdrawal', 'withdrawal_refund']);
            elseif ($type === 'reversal') $builder->where('type', 'commission_reversal');
            $total = $builder->count();
            $rows = $builder->forPage($current, $pageSize)->get()->map(function ($row) {
                $data = $row->toArray();
                // Keep the legacy fields while clients migrate to the unified ledger.
                $data['order_amount'] = (int)($row->meta['order_amount'] ?? 0);
                $data['trade_no'] = (string)($row->trade_no ?? '');
                $data['get_amount'] = (int)$row->amount;
                $data['commission_status'] = $row->status === 'completed' ? 2 : 0;
                $data['created_at'] = $row->created_at ? (int)$row->created_at : time();
                return $data;
            });
            return response([
                'data' => $rows,
                'total' => $total,
            ]);
        }

        $builder = CommissionLog::where('invite_user_id', $request->user['id'])
            ->where('get_amount', '>', 0)
            ->select([
                'id',
                'trade_no',
                'order_amount',
                'get_amount',
                'created_at'
            ])
            ->orderBy('created_at', 'DESC');
        $total = $builder->count();
        $details = $builder->forPage($current, $pageSize)->get()->map(function ($row) {
            return [
                'id' => $row->id,
                'trade_no' => (string)($row->trade_no ?? ''),
                'order_amount' => (int)$row->order_amount,
                'get_amount' => (int)$row->get_amount,
                'commission_status' => 2,
                'created_at' => $row->created_at ? (int)$row->created_at : time(),
            ];
        });
        return response([
            'data' => $details,
            'total' => $total
        ]);
    }

    public function ledger(Request $request)
    {
        $request->attributes->set('invite_ledger', true);
        return $this->details($request);
    }

    public function users(Request $request)
    {
        $current = max((int)$request->input('current', 1), 1);
        $pageSize = min(max((int)$request->input('page_size', 10), 10), 100);
        $builder = User::where('invite_user_id', $request->user['id'])->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
        $total = $builder->count();
        $rows = $builder->forPage($current, $pageSize)->get(['id', 'email', 'created_at']);

        if (Schema::hasTable('v2_referral_reward')) {
            $effectiveIds = ReferralReward::whereIn('invited_user_id', $rows->pluck('id'))
                ->where('reward_type', 'effective_invite')->where('status', 'granted')
                ->pluck('invited_user_id')->mapWithKeys(function ($id) { return [(int)$id => true]; });
        } else {
            $effectiveIds = Order::whereIn('user_id', $rows->pluck('id'))->where('invite_user_id', $request->user['id'])
                ->where('status', 3)->pluck('user_id')->mapWithKeys(function ($id) { return [(int)$id => true]; });
        }

        return response([
            'data' => $rows->map(function ($row) use ($effectiveIds) {
                return [
                    'id' => $row->id,
                    'email' => $this->maskEmail((string)$row->email),
                    'created_at' => $row->created_at,
                    'status' => $effectiveIds->has((int)$row->id) ? 'effective' : 'pending',
                ];
            })->values(),
            'total' => $total,
        ]);
    }

    private function maskEmail(string $email): string
    {
        if (strpos($email, '@') === false) return $email ? substr($email, 0, 1) . '***' : '-';
        [$prefix, $domain] = explode('@', $email, 2);
        $length = strlen($prefix);
        if ($length === 0) return '***@' . $domain;

        $head = substr($prefix, 0, min(2, $length));
        $tailLength = min(2, max(0, $length - 2));
        $tail = $tailLength > 0 ? substr($prefix, -$tailLength) : '';

        return $head . '***' . $tail . '@' . $domain;
    }

    public function fetch(Request $request)
    {
        $codes = InviteCode::where('user_id', $request->user['id'])
            ->where('status', 0)
            ->get();
        $user = User::find($request->user['id']);
        $commission_rate = $this->resolveCommissionRate($user);
        $uncheck_commission_balance = (int)Order::where('status', 3)
            ->where('commission_status', 0)
            ->where('invite_user_id', $request->user['id'])
            ->sum('commission_balance');
        if (config('v2board.commission_distribution_enable', 0)) {
            $uncheck_commission_balance = (int)round(
                $uncheck_commission_balance * (config('v2board.commission_distribution_l1') / 100)
            );
        }
        $stat = [
            //已注册用户数
            (int)User::where('invite_user_id', $request->user['id'])->count(),
            //有效的佣金
            (int)CommissionLog::where('invite_user_id', $request->user['id'])
                ->sum('get_amount'),
            //确认中的佣金
            $uncheck_commission_balance,
            //佣金比例
            (int)$commission_rate,
            //可用佣金
            (int)$user->commission_balance
        ];
        return response([
            'data' => [
                'codes' => $codes,
                'stat' => $stat,
            ]
        ]);
    }

    public function program(Request $request)
    {
        $user = User::find($request->user['id']);
        $commission_rate = $this->resolveCommissionRate($user);
        $program = null;
        $rewardRestriction = null;
        if (Schema::hasTable('v2_referral_reward')) {
            $setting = ReferralSetting::current();
            $referralService = app(ReferralProgramService::class);
            $rewardRestriction = $referralService->rewardRestrictionForInviter($user);
            if (!$setting->enabled) {
                return response(['data' => ['program' => null, 'reward_restriction' => null]]);
            }
            $effectiveCount = ReferralReward::where('user_id', $user->id)
                ->where('reward_type', 'effective_invite')->where('status', 'granted')->count();
            $referralRevenue = (int)Order::where('invite_user_id', $user->id)->where('status', 3)->sum('total_amount');
            $assignedLevelValid = $user->referral_level_id && (!$user->referral_level_expires_at || $user->referral_level_expires_at > time());
            $level = $assignedLevelValid
                ? ReferralLevel::where('id', $user->referral_level_id)->where('enabled', 1)->first()
                : null;
            if (!$level) {
                $level = ReferralLevel::where('enabled', 1)
                    ->where('required_invites', '<=', $effectiveCount)
                    ->where('required_revenue', '<=', $referralRevenue)
                    ->orderBy('sort', 'DESC')->orderBy('id', 'DESC')->first();
            }
            $currentLevelSort = $level ? (int)$level->sort : 0;
            $nextLevelQuery = ReferralLevel::where('enabled', 1)
                ->where('sort', '>', $currentLevelSort)
                ->orderBy('sort')->orderBy('id');
            if (Schema::hasColumn('v2_referral_milestone', 'referral_level_id')) {
                $nextLevelQuery->with(['reward' => function ($query) {
                    $query->where('enabled', 1);
                }]);
            }
            $nextLevel = $nextLevelQuery->first();
            if ($nextLevel && !Schema::hasColumn('v2_referral_milestone', 'referral_level_id')) {
                $nextLevel->setRelation('reward', null);
            }
            $program = [
                'setting' => $setting,
                'effective_invites' => $effectiveCount,
                'referral_revenue' => $referralRevenue,
                'commission_rate' => (int)$commission_rate,
                'level' => $level,
                'level_expires_at' => $user->referral_level_expires_at,
                'next_level' => $nextLevel,
                'recent_rewards' => ReferralReward::where('user_id', $user->id)->where('reward_type', '!=', 'effective_invite')
                    ->orderBy('id', 'DESC')->limit(10)->get(),
            ];
            if (Schema::hasTable('v2_user_coupon')) {
                $grant = UserCoupon::with('template')->where('user_id', $user->id)->where('source', 'referral_newcomer')->orderBy('id', 'DESC')->first();
                if ($grant) $program['newcomer_reward'] = ['status'=>$grant->status,'expires_at'=>$grant->expires_at,'coupon_name'=>$grant->template?$grant->template->name:null];
            }
        }
        return response([
            'data' => [
                'program' => $program,
                'reward_restriction' => $rewardRestriction,
            ]
        ]);
    }

    private function resolveCommissionRate(User $user): int
    {
        try {
            return app(ReferralProgramService::class)->commissionRate($user);
        } catch (\Throwable $exception) {
            // Optional growth-program failures must never break legacy invite
            // codes, statistics or commission balances.
            Log::warning('Referral commission rate fallback used', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
            return $user->commission_rate
                ? (int)$user->commission_rate
                : (int)config('v2board.invite_commission', 10);
        }
    }
}
