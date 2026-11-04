<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Http\Requests\Admin\UserBatchGroup;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Models\ServerGroup;
use App\Services\AuthService;
use App\Services\AccountDeletionService;
use App\Services\CommissionLedgerService;
use App\Services\BalanceLedgerService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) abort(500, '用户不存在');
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        return response([
            'data' => $user->save()
        ]);
    }

    private function filter(Request $request, $builder)
    {
        $filters = $request->input('filter');
        if ($filters) {
            foreach ($filters as $k => $filter) {
                if ($filter['condition'] === '模糊') {
                    $filter['condition'] = 'like';
                    $filter['value'] = "%{$filter['value']}%";
                }
                if ($filter['key'] === 'd' || $filter['key'] === 'transfer_enable') {
                    $filter['value'] = $filter['value'] * 1073741824;
                }
                if ($filter['key'] === 'invite_by_email') {
                    $user = User::where('email', $filter['condition'], $filter['value'])->first();
                    $inviteUserId = isset($user->id) ? $user->id : 0;
                    $builder->where('invite_user_id', $inviteUserId);
                    unset($filters[$k]);
                    continue;
                }
                if ($filter['key'] === 'plan_id' && $filter['value'] == 'null') {
                    $builder->whereNull('plan_id');
                    continue;
                }
                if ($filter['key'] === 'group_id' && $filter['value'] == 'null') {
                    $builder->whereNull('group_id');
                    continue;
                }
                $builder->where($filter['key'], $filter['condition'], $filter['value']);
            }
        }
    }

    public function fetch(UserFetch $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $userModel = User::select(
            DB::raw('*'),
            DB::raw('(u+d) as total_used')
        );
        if (Schema::hasColumn('v2_user', 'deleted_at')) {
            $userModel->whereNull('deleted_at');
        }
        $userModel->orderBy($sort, $sortType);
        $this->filter($request, $userModel);
        $total = $userModel->count();
        $res = $userModel->forPage($current, $pageSize)
            ->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
            //统计在线设备
            $countalive = 0;
            $ips = [];
            $ips_array = Cache::get('ALIVE_IP_USER_'. $res[$i]['id']);
            if ($ips_array) {
                $countalive = $ips_array['alive_ip'];
                foreach($ips_array as $nodetypeid => $data) {
                    if (!is_int($data) && isset($data['aliveips'])) {
                        foreach($data['aliveips'] as $ip_NodeId) {
                            $ip = explode("_", $ip_NodeId)[0];
                            $ips[] = $ip . '_' . $nodetypeid;
                        }
                    }
                }
            }
            $res[$i]['alive_ip'] = $countalive;
            $res[$i]['ips'] = implode(', ', $ips);
            $res[$i]['subscribe_url'] = Helper::getSubscribeUrl($res[$i]['token']);
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function batchGroup(UserBatchGroup $request)
    {
        $group = ServerGroup::findOrFail((int)$request->input('group_id'));
        $builder = $this->batchGroupBuilder($request);
        $total = (clone $builder)->count();
        if ($total < 1) abort(422, '当前筛选条件没有匹配用户');

        DB::beginTransaction();
        try {
            $affected = $builder->update(['group_id' => $group->id, 'updated_at' => time()]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            abort(500, '批量修改权限组失败');
        }

        logger()->info('管理员批量修改用户权限组', [
            'admin_id' => (int)($request->user['id'] ?? 0),
            'target_group_id' => $group->id,
            'target_group_name' => $group->name,
            'matched_users' => $total,
            'affected_users' => $affected,
            'source_query_hash' => hash('sha256', (string)$request->input('source_query', '')),
        ]);

        return response(['data' => ['matched' => $total, 'affected' => $affected]]);
    }

    private function batchGroupBuilder(Request $request)
    {
        $params = [];
        parse_str((string)$request->input('source_query', ''), $params);
        $filterRequest = Request::create('/', 'GET', ['filter' => $params['filter'] ?? []]);
        validator($filterRequest->all(), [
            'filter' => 'nullable|array|max:30',
            'filter.*.key' => 'required|in:id,email,transfer_enable,device_limit,d,expired_at,uuid,token,invite_by_email,invite_user_id,plan_id,group_id,banned,remarks,is_admin',
            'filter.*.condition' => 'required|in:>,<,=,>=,<=,模糊,!=',
            'filter.*.value' => 'required',
        ])->validate();
        $builder = User::query();
        $this->filter($filterRequest, $builder);
        return $builder;
    }

    public function getUserInfoById(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $user = User::find($request->input('id'));
        if ($user->invite_user_id) {
            $user['invite_user'] = User::find($user->invite_user_id);
        }
        return response([
            'data' => $user
        ]);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();
        $user = User::find($request->input('id'));
        if (!$user) {
            abort(500, '用户不存在');
        }
        if (User::where('email', $params['email'])->first() && $user->email !== $params['email']) {
            abort(500, '邮箱已被使用');
        }
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
        } else {
            unset($params['password']);
        }
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                abort(500, '订阅计划不存在');
            }
            $params['group_id'] = $plan->group_id;
        } else {
            $params['group_id'] = null;
        }
        if ($request->input('invite_user_email')) {
            $inviteUser = User::where('email', $request->input('invite_user_email'))->first();
            if ($inviteUser) {
                $params['invite_user_id'] = $inviteUser->id;
            }
        } else {
            $params['invite_user_id'] = null;
        }

        if (isset($params['banned']) && (int)$params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSession();
        }

        $commissionBefore = (int)$user->commission_balance;
        $balanceBefore = (int)$user->balance;
        try {
            $user->update($params);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }
        $freshUser = $user->fresh();
        $commissionAfter = (int)$freshUser->commission_balance;
        $balanceAfter = (int)$freshUser->balance;
        if ($commissionAfter !== $commissionBefore) {
            app(CommissionLedgerService::class)->record([
                'user_id' => $user->id,
                'type' => 'admin_adjustment',
                'amount' => $commissionAfter - $commissionBefore,
                'balance_before' => $commissionBefore,
                'balance_after' => $commissionAfter,
                'source_key' => 'admin_adjustment:' . $user->id . ':' . time() . ':' . uniqid(),
                'source_type' => 'admin',
                'source_id' => $request->user['id'] ?? null,
                'description' => '管理员调整佣金余额',
            ]);
        }
        if ($balanceAfter !== $balanceBefore) {
            app(BalanceLedgerService::class)->record([
                'user_id' => $user->id,
                'type' => 'admin_adjustment',
                'amount' => $balanceAfter - $balanceBefore,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'source_key' => 'admin_balance_adjustment:' . $user->id . ':' . time() . ':' . uniqid(),
                'source_type' => 'admin',
                'source_id' => $request->user['id'] ?? null,
                'description' => '管理员调整余额',
            ]);
        }
        return response([
            'data' => true
        ]);
    }

    public function dumpCSV(Request $request)
    {
        $userModel = User::orderBy('id', 'asc');
        $this->filter($request, $userModel);
        $res = $userModel->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
        }

        $data = "邮箱,余额,推广佣金,总流量,设备数限制,剩余流量,套餐到期时间,订阅计划,订阅地址\r\n";
        foreach($res as $user) {
            $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
            $balance = $user['balance'] / 100;
            $commissionBalance = $user['commission_balance'] / 100;
            $transferEnable = $user['transfer_enable'] ? $user['transfer_enable'] / 1073741824 : 0;
            $deviceLimit = $user['devce_limit'] ? $user['devce_limit'] : NULL;
            $notUseFlow = (($user['transfer_enable'] - ($user['u'] + $user['d'])) / 1073741824) ?? 0;
            $planName = $user['plan_name'] ?? '无订阅';
            $subscribeUrl =  Helper::getSubscribeUrl($user['token']);
            $data .= "{$user['email']},{$balance},{$commissionBalance},{$transferEnable}, {$deviceLimit}, {$notUseFlow},{$expireDate},{$planName},{$subscribeUrl}\r\n";

        }
        echo "\xEF\xBB\xBF" . $data;
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            if ($request->input('plan_id')) {
                $plan = Plan::find($request->input('plan_id'));
                if (!$plan) {
                    abort(500, '订阅计划不存在');
                }
            }
            $user = [
                'email' => $request->input('email_prefix') . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : NULL,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 0,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : NULL,
                'expired_at' => $request->input('expired_at') ?? NULL,
                'uuid' => Helper::guid(true),
                'token' => Helper::guid()
            ];
            if (User::where('email', $user['email'])->first()) {
                abort(500, '邮箱已存在于系统中');
            }
            $user['password'] = password_hash($request->input('password') ?? $user['email'], PASSWORD_DEFAULT);
            if (!User::create($user)) {
                abort(500, '生成失败');
            }
            return response([
                'data' => true
            ]);
        }
        if ($request->input('generate_count')) {
            $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        if ($request->input('plan_id')) {
            $plan = Plan::find($request->input('plan_id'));
            if (!$plan) {
                abort(500, '订阅计划不存在');
            }
        }
        $users = [];
        for ($i = 0;$i < $request->input('generate_count');$i++) {
            $user = [
                'email' => Helper::randomChar(6) . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : NULL,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 0,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : NULL,
                'expired_at' => $request->input('expired_at') ?? NULL,
                'uuid' => Helper::guid(true),
                'token' => Helper::guid(),
                'created_at' => time(),
                'updated_at' => time()
            ];
            $user['password'] = password_hash($request->input('password') ?? $user['email'], PASSWORD_DEFAULT);
            array_push($users, $user);
        }
        DB::beginTransaction();
        if (!User::insert($users)) {
            DB::rollBack();
            abort(500, '生成失败');
        }
        DB::commit();
        $data = "账号,密码,过期时间,UUID,创建时间,订阅地址\r\n";
        foreach($users as $user) {
            $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
            $createDate = date('Y-m-d H:i:s', $user['created_at']);
            $password = $request->input('password') ?? $user['email'];
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);
            $data .= "{$user['email']},{$password},{$expireDate},{$user['uuid']},{$createDate},{$subscribeUrl}\r\n";
        }
        echo $data;
    }

    public function sendMail(UserSendMail $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        $sendAt = $request->filled('send_at') ? (int)$request->input('send_at') : null;
        $recipientCount = 0;
        foreach ($builder->cursor() as $user) {
            $job = SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $request->input('subject'),
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'content' => $request->input('content')
                ]
            ], 'send_email_mass');
            if ($sendAt) $job->delay(max(0, $sendAt - time()));
            $recipientCount++;
        }

        return response([
            'data' => ['scheduled_at' => $sendAt, 'recipient_count' => $recipientCount]
        ]);
    }

    public function ban(Request $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        try {
            $builder->each(function ($user){
                $authService = new AuthService($user);
                $authService->removeAllSession();
            });
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Exception $e) {
            abort(500, '处理失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function allDel(Request $request, AccountDeletionService $deletionService)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);

        try {
            $ids = $builder->where('is_admin', 0)->whereNull('deleted_at')->pluck('id');
            User::whereIn('id', $ids)->orderBy('id')->each(function ($user) use ($deletionService, $request) {
                $deletionService->anonymize(
                    $user,
                    'admin',
                    (int)$request->user['id'],
                    (string)$request->input('reason', '管理员批量注销')
                );
            });
        } catch (\Exception $e) {
            report($e);
            abort(500, '批量删除用户信息失败');
        }

        return response([
            'data' => true,
            'affected' => isset($ids) ? $ids->count() : 0,
        ]);
    }

    public function delUser(Request $request, AccountDeletionService $deletionService)
    {
        $user = User::find($request->input('id'));
        if (!$user) {
            abort(500, '用户不存在');
        }
        if ($user->is_admin) {
            abort(500, '不能删除管理员账号');
        }
        try {
            $deletionService->anonymize(
                $user,
                'admin',
                (int)$request->user['id'],
                (string)$request->input('reason', '管理员注销')
            );
        } catch (\Exception $e) {
            report($e);
            abort(500, '删除用户失败');
        }

        return response([
            'data' => true
        ]);
    }
}
