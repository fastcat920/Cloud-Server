<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\CouponTemplate;
use App\Models\ReferralVisit;
use App\Models\ReferralLevel;
use App\Models\ReferralMilestone;
use App\Models\ReferralReward;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Services\ReferralProgramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReferralController extends Controller
{
    public function dashboard(Request $request)
    {
        if ($request->boolean('refresh')) Cache::forget('admin_referral_dashboard');
        $data = Cache::remember('admin_referral_dashboard', 30, function () {
            $invitees = User::whereNotNull('invite_user_id')->count();
            $effectiveQuery = ReferralReward::where('reward_type', 'effective_invite')->where('status', 'granted');
            $effective = (clone $effectiveQuery)->count();
            return [
                'setting' => ReferralSetting::current(),
                'registered_invites' => $invitees,
                'effective_invites' => $effective,
                'conversion_rate' => $invitees ? round($effective * 100 / $invitees, 2) : 0,
                'referral_revenue' => (int)Order::whereNotNull('invite_user_id')->where('status', 3)->sum('total_amount'),
                'reward_total' => (int)ReferralReward::whereIn('reward_type', ['balance', 'commission_balance'])->where('status', 'granted')->sum('reward_value'),
                'active_promoters' => (clone $effectiveQuery)->distinct()->count('user_id'),
                'coupon_templates' => CouponTemplate::where('enabled', 1)->orderBy('id', 'DESC')->get(['id', 'name', 'name_en', 'ends_at']),
            ];
        });
        return response(['data' => $data]);
    }

    public function saveSetting(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'first_order_min' => 'required|integer|min:0',
            'invitee_reward' => 'required|integer|min:0',
            'newcomer_coupon_template_id' => 'nullable|integer|exists:v2_coupon_template,id',
            'base_commission_rate' => 'required|integer|min:0|max:100',
            'freeze_days' => 'required|integer|min:0|max:365',
            'monthly_reward_limit' => 'nullable|integer|min:0',
            'no_active_plan_reward_policy' => 'sometimes|string|in:allow_all,block_inviter_commission,block_invitee_rewards,block_both',
        ]);
        $setting = ReferralSetting::first();
        if ($setting) $setting->update($data);
        else $setting = ReferralSetting::create($data);
        Cache::forget('admin_referral_dashboard');
        return response(['data' => $setting]);
    }

    public function levels()
    {
        $query = ReferralLevel::orderBy('sort')->orderBy('id');
        if (Schema::hasColumn('v2_referral_milestone', 'referral_level_id')) $query->with('reward');
        return response(['data' => $query->get()]);
    }

    public function saveLevel(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'name_en' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'description_en' => 'nullable|string|max:255',
            'sort' => 'required|integer|min:1|max:4294967295',
            'required_invites' => 'required|integer|min:0',
            'required_revenue' => 'required|integer|min:0',
            'commission_rate' => 'required|integer|min:0|max:100',
            'member_discount' => 'required|integer|min:0|max:100',
            'valid_days' => 'required|integer|min:0|max:3650',
            'retain_invites' => 'required|integer|min:0',
            'retain_revenue' => 'sometimes|integer|min:0',
            'enabled' => 'required|boolean',
            'reward_type' => 'sometimes|in:none,balance,commission_balance,traffic,duration',
            'reward_value' => 'nullable|integer|min:0',
        ]);

        $rewardType = array_key_exists('reward_type', $data) ? $data['reward_type'] : null;
        $rewardValue = (int)($data['reward_value'] ?? 0);
        unset($data['reward_type'], $data['reward_value']);
        if ($rewardType !== null && $rewardType !== 'none' && $rewardValue < 1) {
            abort(422, '启用达标奖励时，奖励数值必须大于 0');
        }

        $levelId = $request->input('id');
        $existingLevel = $levelId ? ReferralLevel::findOrFail($levelId) : null;
        $isInitialLevel = (int)$data['required_invites'] === 0 && (int)$data['required_revenue'] === 0;
        $wasInitialLevel = $existingLevel
            && (int)$existingLevel->required_invites === 0
            && (int)$existingLevel->required_revenue === 0;
        if ($wasInitialLevel && !$isInitialLevel) {
            abort(422, '初始等级的升级条件必须保持为 0 邀请、0 成交额');
        }
        if ($isInitialLevel && !$data['enabled']) {
            abort(422, '0 邀请初始等级必须保持启用');
        }
        if ($isInitialLevel && (int)$data['valid_days'] !== 0) {
            abort(422, '0 邀请初始等级必须永久有效，请将等级有效期设置为 0');
        }
        if ($isInitialLevel && $rewardType !== null && $rewardType !== 'none') {
            abort(422, '0 邀请初始等级不能配置达标奖励');
        }

        $sameRequirementLevel = ReferralLevel::where('id', '!=', $levelId ?: 0)
            ->where('required_invites', $data['required_invites'])
            ->where('required_revenue', $data['required_revenue'])
            ->first();
        if ($sameRequirementLevel) {
            abort(422, '升级条件不能重复，已存在相同条件的等级“' . $sameRequirementLevel->name . '”');
        }

        $sameOrderLevel = ReferralLevel::where('id', '!=', $levelId ?: 0)->where('sort', $data['sort'])->first();
        if ($sameOrderLevel) {
            abort(422, '等级顺序不能重复，当前顺序已被“' . $sameOrderLevel->name . '”使用');
        }

        $lowerConflict = ReferralLevel::where('id', '!=', $levelId ?: 0)
            ->where('sort', '<', $data['sort'])
            ->where(function ($query) use ($data) {
                $query->where('required_invites', '>', $data['required_invites'])
                    ->orWhere('required_revenue', '>', $data['required_revenue']);
            })->first();
        if ($lowerConflict) {
            abort(422, '等级顺序与升级条件冲突：较低等级“' . $lowerConflict->name . '”的升级要求不能高于当前等级');
        }

        $higherConflict = ReferralLevel::where('id', '!=', $levelId ?: 0)
            ->where('sort', '>', $data['sort'])
            ->where(function ($query) use ($data) {
                $query->where('required_invites', '<', $data['required_invites'])
                    ->orWhere('required_revenue', '<', $data['required_revenue']);
            })->first();
        if ($higherConflict) {
            abort(422, '等级顺序与升级条件冲突：较高等级“' . $higherConflict->name . '”的升级要求不能低于当前等级');
        }

        $level = DB::transaction(function () use ($existingLevel, $data, $rewardType, $rewardValue) {
            $level = $existingLevel ?: new ReferralLevel();
            $level->fill($data)->save();

            if ($rewardType !== null && Schema::hasColumn('v2_referral_milestone', 'referral_level_id')) {
                $reward = ReferralMilestone::where('referral_level_id', $level->id)->lockForUpdate()->first();
                if ($rewardType === 'none') {
                    if ($reward) {
                        $reward->enabled = 0;
                        $reward->save();
                    }
                } else {
                    $reward = $reward ?: new ReferralMilestone();
                    $reward->fill([
                        'referral_level_id' => $level->id,
                        'name' => $level->name,
                        'name_en' => $level->name_en,
                        'required_invites' => $level->required_invites,
                        'reward_type' => $rewardType,
                        'reward_value' => $rewardValue,
                        'enabled' => 1,
                    ])->save();
                }
            }

            return Schema::hasColumn('v2_referral_milestone', 'referral_level_id')
                ? $level->load('reward')
                : $level;
        });
        return response(['data' => $level]);
    }

    public function dropLevel(Request $request)
    {
        $level = ReferralLevel::findOrFail($request->input('id'));
        if ((int)$level->required_invites === 0 && (int)$level->required_revenue === 0) {
            $initialLevelCount = ReferralLevel::where('required_invites', 0)->where('required_revenue', 0)->count();
            if ($initialLevelCount <= 1) {
                abort(422, '初始等级不能删除，您可以编辑它的名称、描述和权益');
            }
        }
        $result = DB::transaction(function () use ($level) {
            if (Schema::hasColumn('v2_referral_milestone', 'referral_level_id')) {
                ReferralMilestone::where('referral_level_id', $level->id)->delete();
            }
            return (bool)$level->delete();
        });
        return response(['data' => $result]);
    }

    public function milestones()
    {
        return response(['data' => ReferralMilestone::orderBy('required_invites')->get()]);
    }


    public function funnel(Request $request)
    {
        $days = min(max((int)$request->input('days', 30), 7), 365);
        $from = strtotime('-' . ($days - 1) . ' days midnight');
        $visits = ReferralVisit::where('created_at', '>=', $from);
        $registrations = User::whereNotNull('invite_user_id')->where('created_at', '>=', $from);
        $firstCompletedPlanOrderIds = function () {
            return Order::where('status', 3)
                ->whereIn('type', [1, 2, 3])
                ->whereNotNull('invite_user_id')
                ->selectRaw('MIN(id)')
                ->groupBy('user_id');
        };
        $registrationUserIds = function () use ($from) {
            return User::whereNotNull('invite_user_id')
                ->where('created_at', '>=', $from)
                ->select('id');
        };
        $completedPlanOrders = Order::whereNotNull('invite_user_id')
            ->where('status', 3)
            ->whereIn('type', [1, 2, 3])
            ->where('created_at', '>=', $from);
        $firstOrders = Order::whereNotNull('invite_user_id')
            ->whereIn('id', $firstCompletedPlanOrderIds())
            ->where('created_at', '>=', $from);
        $repeatPurchases = Order::whereNotNull('invite_user_id')
            ->where('type', 1)
            ->where('status', 3)
            ->whereNotIn('id', $firstCompletedPlanOrderIds())
            ->where('created_at', '>=', $from);
        $renewals = Order::whereNotNull('invite_user_id')->where('type', 2)->where('status', 3)->where('created_at', '>=', $from);
        $planChanges = Order::whereNotNull('invite_user_id')->where('type', 3)->where('status', 3)->where('created_at', '>=', $from);
        $rewards = ReferralReward::where('created_at', '>=', $from);

        // 每项指标只执行一次分组查询，避免按 30 天逐日查询产生上百次数据库请求。
        $dailyCounts = function ($query) use ($from, $days) {
            $to = $from + $days * 86400;
            return $query
                ->where('created_at', '<', $to)
                ->selectRaw("FLOOR((created_at - {$from}) / 86400) AS day_index, COUNT(*) AS aggregate")
                ->groupBy('day_index')
                ->pluck('aggregate', 'day_index')
                ->map(function ($value) { return (int)$value; })
                ->all();
        };

        $dailyVisits = $dailyCounts(clone $visits);
        $dailyRegistrations = $dailyCounts(clone $registrations);
        $dailyFirstOrders = $dailyCounts(clone $firstOrders);
        $dailyRepeatPurchases = $dailyCounts(clone $repeatPurchases);
        $dailyRenewals = $dailyCounts(clone $renewals);
        $dailyPlanChanges = $dailyCounts(clone $planChanges);
        $trend = [];
        for ($i = 0; $i < $days; $i++) {
            $trend[] = [
                'date' => date('Y-m-d', $from + $i * 86400),
                'visits' => $dailyVisits[$i] ?? 0,
                'registrations' => $dailyRegistrations[$i] ?? 0,
                'first_orders' => $dailyFirstOrders[$i] ?? 0,
                'repeat_purchases' => $dailyRepeatPurchases[$i] ?? 0,
                'renewals' => $dailyRenewals[$i] ?? 0,
                'plan_changes' => $dailyPlanChanges[$i] ?? 0,
            ];
        }
        $aggregate = function (array $rows, string $format) {
            return collect($rows)->groupBy(function ($row) use ($format) { return date($format, strtotime($row['date'])); })->map(function ($items, $key) {
                return [
                    'date' => $key,
                    'visits' => $items->sum('visits'),
                    'registrations' => $items->sum('registrations'),
                    'first_orders' => $items->sum('first_orders'),
                    'repeat_purchases' => $items->sum('repeat_purchases'),
                    'renewals' => $items->sum('renewals'),
                    'plan_changes' => $items->sum('plan_changes'),
                ];
            })->values();
        };
        $revenue = (int)(clone $completedPlanOrders)->sum('total_amount');
        $rewardCost = (int)(clone $rewards)->whereIn('reward_type', ['balance', 'commission_balance'])->where('status', 'granted')->sum('reward_value');
        $cohortFirstBuyers = Order::whereIn('id', $firstCompletedPlanOrderIds())
            ->whereIn('user_id', $registrationUserIds())
            ->count();
        $renewalUsers = Order::where('type', 2)
            ->where('status', 3)
            ->whereIn('user_id', $registrationUserIds())
            ->distinct()
            ->count('user_id');
        $registrationCount = (clone $registrations)->count();
        return response(['data' => [
            'summary' => [
                'visits' => (clone $visits)->count(),
                'registrations' => $registrationCount,
                'verified' => config('v2board.email_verify') ? $registrationCount : null,
                'first_orders' => (clone $firstOrders)->count(),
                'first_purchase_users' => $cohortFirstBuyers,
                'registration_to_first_rate' => $registrationCount ? round($cohortFirstBuyers * 100 / $registrationCount, 2) : 0,
                'renewal_users' => $renewalUsers,
                'renewal_rate' => $cohortFirstBuyers ? round($renewalUsers * 100 / $cohortFirstBuyers, 2) : 0,
                'pending_rewards' => (clone $rewards)->where('status', 'pending')->count(),
                'reversed_rewards' => (clone $rewards)->where('status', 'reversed')->count(),
                'revenue' => $revenue,
                'reward_cost' => $rewardCost,
                'roi' => $rewardCost ? round(($revenue - $rewardCost) / $rewardCost, 2) : null,
            ],
            'trend' => $trend,
            'weekly_trend' => $aggregate($trend, 'o-W'),
            'monthly_trend' => $aggregate($trend, 'Y-m'),
            'channels' => ReferralVisit::where('created_at', '>=', $from)->select('channel', DB::raw('COUNT(*) visits'), DB::raw('COUNT(user_id) registrations'))->groupBy('channel')->orderBy('visits', 'DESC')->get(),
            'plans' => (clone $completedPlanOrders)->select('plan_id', DB::raw('COUNT(*) orders'), DB::raw('SUM(total_amount) revenue'))->groupBy('plan_id')->orderBy('orders', 'DESC')->get(),
        ]]);
    }

    public function saveMilestone(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'name_en' => 'nullable|string|max:100',
            'required_invites' => 'required|integer|min:1',
            'reward_type' => 'required|in:balance,commission_balance,traffic,duration',
            'reward_value' => 'required|integer|min:1',
            'enabled' => 'required|boolean',
        ]);
        $milestone = $request->input('id') ? ReferralMilestone::findOrFail($request->input('id')) : new ReferralMilestone();
        $milestone->fill($data)->save();
        return response(['data' => $milestone]);
    }

    public function dropMilestone(Request $request)
    {
        $milestone = ReferralMilestone::findOrFail($request->input('id'));
        $result = (bool)$milestone->delete();
        return response(['data' => $result]);
    }

    public function rewards(Request $request)
    {
        $pageSize = min(max((int)$request->input('pageSize', 20), 1), 100);
        $builder = ReferralReward::orderBy('id', 'DESC');
        if ($request->input('status')) $builder->where('status', $request->input('status'));
        if ($request->input('type')) $builder->where('reward_type', $request->input('type'));
        if ($request->input('keyword')) {
            $userIds = User::where('email', 'like', '%' . trim($request->input('keyword')) . '%')->pluck('id');
            $builder->where(function ($query) use ($userIds) {
                $query->whereIn('user_id', $userIds)->orWhereIn('invited_user_id', $userIds);
            });
        }
        if ($request->input('from')) $builder->where('created_at', '>=', strtotime($request->input('from')) ?: 0);
        if ($request->input('to')) $builder->where('created_at', '<', (strtotime($request->input('to')) ?: time()) + 86400);
        $total = $builder->count();
        $rows = $builder->forPage(max((int)$request->input('current', 1), 1), $pageSize)->get();
        $users = User::whereIn('id', $rows->pluck('user_id')->merge($rows->pluck('invited_user_id'))->filter()->unique())
            ->pluck('email', 'id');
        $rows->each(function ($row) use ($users) {
            $row->user_email = $users->get($row->user_id);
            $row->invited_user_email = $users->get($row->invited_user_id);
        });
        return response(['data' => $rows, 'total' => $total]);
    }

    public function reverseReward(Request $request, ReferralProgramService $service)
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'reason' => 'nullable|string|max:200',
        ]);
        $reward = ReferralReward::findOrFail($data['id']);
        if (!$reward->order_id) abort(422, '该流水没有关联订单，无法按订单撤销');
        try {
            $count = $service->reverseOrderRewards((int)$reward->order_id, trim($data['reason'] ?? ''));
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return response(['data' => ['reversed' => $count]]);
    }

    public function relations(Request $request)
    {
        $pageSize = min(max((int)$request->input('pageSize', 20), 1), 100);
        $builder = User::whereNotNull('invite_user_id')->orderBy('id', 'DESC');
        if ($request->input('keyword')) {
            $keyword = trim($request->input('keyword'));
            $inviterIds = User::where('email', 'like', '%' . $keyword . '%')->pluck('id');
            $builder->where(function ($query) use ($keyword, $inviterIds) {
                $query->where('email', 'like', '%' . $keyword . '%')->orWhereIn('invite_user_id', $inviterIds);
            });
        }
        if ($request->input('status') === 'effective') {
            $builder->whereIn('id', ReferralReward::where('reward_type', 'effective_invite')->where('status', 'granted')->pluck('invited_user_id'));
        } elseif ($request->input('status') === 'pending') {
            $builder->whereNotIn('id', ReferralReward::where('reward_type', 'effective_invite')->where('status', 'granted')->pluck('invited_user_id'));
        }
        $total = $builder->count();
        $rows = $builder->forPage(max((int)$request->input('current', 1), 1), $pageSize)->get(['id', 'email', 'invite_user_id', 'created_at']);
        $inviters = User::whereIn('id', $rows->pluck('invite_user_id')->unique())->pluck('email', 'id');
        $effectiveIds = ReferralReward::whereIn('invited_user_id', $rows->pluck('id'))->where('reward_type', 'effective_invite')->pluck('invited_user_id')->flip();
        $rows->each(function ($row) use ($inviters, $effectiveIds) {
            $row->inviter_email = $inviters->get($row->invite_user_id);
            $row->effective = $effectiveIds->has($row->id);
        });
        return response(['data' => $rows, 'total' => $total]);
    }

    public function relationDetail(Request $request)
    {
        $user = User::findOrFail($request->input('user_id'));
        $inviter = $user->invite_user_id ? User::find($user->invite_user_id) : null;
        return response(['data' => [
            'user' => $user->only([
                'id', 'email', 'invite_user_id', 'created_at',
                'invite_commission_eligible', 'invitee_reward_eligible', 'invite_reward_evaluated_at',
            ]),
            'inviter' => $inviter ? $inviter->only(['id', 'email']) : null,
            'orders' => Order::where('user_id', $user->id)->orderBy('id', 'DESC')->limit(20)->get(['id', 'trade_no', 'plan_id', 'total_amount', 'status', 'created_at']),
            'rewards' => ReferralReward::where('invited_user_id', $user->id)->orderBy('id', 'DESC')->get(),
        ]]);
    }

    public function changeRelation(Request $request, ReferralProgramService $service)
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:v2_user,id', 'inviter_id' => 'nullable|integer|exists:v2_user,id']);
        if ($data['inviter_id'] && (int)$data['inviter_id'] === (int)$data['user_id']) abort(422, '不能邀请自己');
        DB::transaction(function () use ($data, $service) {
            $user = User::where('id', $data['user_id'])->lockForUpdate()->firstOrFail();
            if (ReferralReward::where('invited_user_id', $user->id)->where('status', 'granted')->exists()) {
                abort(422, '该关系已产生有效奖励，请先撤销关联订单奖励');
            }
            if ($data['inviter_id']) {
                $cursor = User::find($data['inviter_id']);
                for ($depth = 0; $cursor && $depth < 100; $depth++) {
                    if ((int)$cursor->id === (int)$user->id) abort(422, '调整后会形成循环邀请关系');
                    $cursor = $cursor->invite_user_id ? User::find($cursor->invite_user_id) : null;
                }
            }
            $user->invite_user_id = $data['inviter_id'] ?: null;
            $service->snapshotReferralEligibility($user);
            $user->save();
        });
        Cache::forget('admin_referral_dashboard');
        return response(['data' => true]);
    }
}
