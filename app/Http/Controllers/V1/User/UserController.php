<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserChangeEmail;
use App\Http\Requests\User\UserSendChangeEmailVerify;
use App\Http\Requests\User\UserRedeemGiftCard;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Giftcard;
use App\Models\GiftcardRedemption;
use App\Models\BalanceLedger;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Jobs\SendEmailJob;
use App\Services\AuthService;
use App\Services\AccountDeletionService;
use App\Services\CommissionLedgerService;
use App\Services\BalanceLedgerService;
use App\Services\OrderService;
use App\Services\UserService;
use App\Support\ContentLocale;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function balanceRecords(Request $request)
    {
        $current = max((int)$request->input('current', 1), 1);
        $pageSize = 10;
        if (!Schema::hasTable('v2_balance_ledger')) {
            return response(['data' => [], 'total' => 0, 'current' => $current, 'pageSize' => $pageSize]);
        }

        $builder = BalanceLedger::where('user_id', $request->user['id'])
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');
        $total = $builder->count();
        $data = $builder->forPage($current, $pageSize)->get([
            'id', 'type', 'amount', 'balance_before', 'balance_after', 'status',
            'trade_no', 'description', 'meta', 'created_at'
        ]);

        return response([
            'data' => $data,
            'total' => $total,
            'current' => $current,
            'pageSize' => $pageSize,
        ]);
    }

    public function giftcardRedemptions(Request $request)
    {
        $current = max((int)$request->input('current', 1), 1);
        $pageSize = min(max((int)$request->input('pageSize', 10), 1), 50);
        $builder = GiftcardRedemption::where('user_id', $request->user['id'])
            ->orderBy('redeemed_at', 'DESC')
            ->orderBy('id', 'DESC');

        $total = $builder->count();
        $records = $builder->forPage($current, $pageSize)->get();
        $planNames = Plan::whereIn('id', $records->pluck('plan_id')->filter()->unique())
            ->get()
            ->each(function ($plan) use ($request) {
                ContentLocale::localize($plan, ['name'], $request);
            })
            ->pluck('name', 'id');
        $giftcardCodes = Giftcard::whereIn('id', $records->pluck('giftcard_id')->unique())
            ->pluck('code', 'id');

        $data = $records->map(function ($record) use ($planNames, $giftcardCodes) {
            $code = $giftcardCodes->get($record->giftcard_id, $record->code_snapshot);
            return [
                'id' => $record->id,
                'giftcard_name' => $record->name_snapshot,
                'code_masked' => $this->maskGiftcardCode($code),
                'type' => $record->type,
                'value' => $record->value,
                'plan_id' => $record->plan_id,
                'plan_name' => $record->plan_id ? $planNames->get($record->plan_id) : null,
                'redeemed_at' => $record->redeemed_at,
            ];
        });

        return response([
            'data' => $data,
            'total' => $total,
            'current' => $current,
            'pageSize' => $pageSize,
        ]);
    }

    public function sendDeleteAccountVerify(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user || $user->deleted_at) {
            abort(500, __('The user does not exist'));
        }

        $rateLimitKey = 'delete-account-email:' . $user->id . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($rateLimitKey, 3600);

        $lastSendKey = CacheKey::get('LAST_SEND_DELETE_ACCOUNT_VERIFY_TIMESTAMP', $user->id);
        if (Cache::has($lastSendKey)) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }

        $code = (string)random_int(100000, 999999);
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => config('v2board.app_name', 'V2Board') . __('Account deletion verification code'),
            'template_name' => 'verify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'code' => $code,
                'url' => config('v2board.app_url')
            ]
        ]);

        Cache::put(CacheKey::get('DELETE_ACCOUNT_VERIFY_CODE', $user->id), $code, 300);
        Cache::put($lastSendKey, time(), 60);
        return response(['data' => true]);
    }

    public function deleteAccount(Request $request, AccountDeletionService $deletionService)
    {
        $request->validate([
            'email_code' => 'required|digits:6',
            'confirm' => 'required|in:DELETE',
        ]);

        $user = User::find($request->user['id']);
        if (!$user || $user->deleted_at) {
            abort(500, __('The user does not exist'));
        }

        $codeKey = CacheKey::get('DELETE_ACCOUNT_VERIFY_CODE', $user->id);
        $cachedCode = Cache::get($codeKey);
        if ($cachedCode === null || !hash_equals((string)$cachedCode, (string)$request->input('email_code'))) {
            abort(500, __('Incorrect email verification code'));
        }

        $deletionService->anonymize($user, 'user', null, 'User requested account deletion');
        Cache::forget($codeKey);
        Cache::forget(CacheKey::get('LAST_SEND_DELETE_ACCOUNT_VERIFY_TIMESTAMP', $user->id));
        return response(['data' => true]);
    }

    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->getSessions()
        ]);
    }

    public function removeActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->removeSession($request->input('session_id'))
        ]);
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user['id'] ? true : false
        ];
        if ($request->user['is_admin']) {
            $data['is_admin'] = true;
        }
        return response([
            'data' => $data
        ]);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $request->input('old_password'),
            $user->password
        )) {
            abort(500, __('The old password is wrong'));
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            abort(500, __('Save failed'));
        }
        $authService = new AuthService($user);
        $authService->removeAllSession();
        return response([
            'data' => true
        ]);
    }

    public function sendChangeEmailVerify(UserSendChangeEmailVerify $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $request->input('password'),
            $user->password
        )) {
            abort(500, __('The password is wrong'));
        }

        $newEmail = strtolower(trim((string)$request->input('new_email')));
        if ((int)config('v2board.email_whitelist_enable', 0) && !Helper::emailSuffixVerify(
            $newEmail,
            config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT)
        )) {
            abort(500, __('Email suffix is not in the Whitelist'));
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $newEmail)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if (strcasecmp($user->email, $newEmail) === 0) {
            abort(500, __('The new email must be different from the current email'));
        }
        if (User::whereRaw('LOWER(email) = ?', [$newEmail])->exists()) {
            abort(500, __('Email already exists'));
        }

        $rateLimitKey = 'change-email:' . $user->id . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($rateLimitKey, 3600);

        $lastSendKey = CacheKey::get('LAST_SEND_CHANGE_EMAIL_VERIFY_TIMESTAMP', $user->id . ':' . $newEmail);
        if (Cache::has($lastSendKey)) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }

        $code = (string)random_int(100000, 999999);
        SendEmailJob::dispatch([
            'email' => $newEmail,
            'subject' => config('v2board.app_name', 'V2Board') . __('Email verification code'),
            'template_name' => 'verify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'code' => $code,
                'url' => config('v2board.app_url')
            ]
        ]);

        Cache::put(CacheKey::get('CHANGE_EMAIL_VERIFY_CODE', $user->id . ':' . $newEmail), $code, 300);
        Cache::put($lastSendKey, time(), 60);
        return response(['data' => true]);
    }

    public function changeEmail(UserChangeEmail $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $request->input('password'),
            $user->password
        )) {
            abort(500, __('The password is wrong'));
        }

        $newEmail = strtolower(trim((string)$request->input('new_email')));
        if (strcasecmp($user->email, $newEmail) === 0) {
            abort(500, __('The new email must be different from the current email'));
        }
        if (User::whereRaw('LOWER(email) = ?', [$newEmail])->where('id', '!=', $user->id)->exists()) {
            abort(500, __('Email already exists'));
        }

        $codeKey = CacheKey::get('CHANGE_EMAIL_VERIFY_CODE', $user->id . ':' . $newEmail);
        $cachedCode = Cache::get($codeKey);
        $inputCode = (string)$request->input('email_code');
        if ($cachedCode === null || !hash_equals((string)$cachedCode, $inputCode)) {
            abort(500, __('Incorrect email verification code'));
        }

        $user->email = $newEmail;
        try {
            if (!$user->save()) {
                abort(500, __('Save failed'));
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if ((string)$e->getCode() === '23000') {
                abort(500, __('Email already exists'));
            }
            throw $e;
        }

        Cache::forget($codeKey);
        Cache::forget(CacheKey::get('LAST_SEND_CHANGE_EMAIL_VERIFY_TIMESTAMP', $user->id . ':' . $newEmail));
        $authService = new AuthService($user);
        $authService->removeAllSession();
        return response(['data' => true]);
    }

    public function newPeriod(Request $request) 
    {
        if (!config('v2board.allow_new_period', 0)) {
            abort(500, __('Renewal is not allowed'));
        }
        DB::beginTransaction();
        try {
            $user = User::find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            if (($user->u + $user->d) * 100 < $user->transfer_enable * 90) {
                abort(500, __('You need to use at least 90% of your traffic before starting the next period'));
            }
            $userService = new UserService();
            $reset_day = $userService->getResetDay($user);
            if ($reset_day === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }
            unset($user->plan);
            $reset_period = $userService->getResetPeriod($user);
            if ($reset_period === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }
            switch ($reset_period) {
                case 1:
                    $reset_day = 30;
                    $reset_period = 30;
                    break;
                case 30:
                    break;
                case 12:
                    $reset_day = 365;
                    $reset_period = 365;
                    break;
                case 365:
                    break;
                default:
                    abort(500, __('Invalid reset period'));
            }
            if ($reset_day <= 0) {
                $reset_day = $reset_period;
            }
            if ($user->expired_at !== null && ($reset_period + 1) * 86400 < $user->expired_at - time()) {
                if (!$user->update(
                    [
                        'expired_at' => $user->expired_at - $reset_day * 86400,
                        'u' => 0,
                        'd' => 0
                    ]
                )) {
                    throw new \Exception(__('Save failed'));
                }
            } else {
                abort(500, __('You do not have enough time to renew your subscription'));
            }

            DB::commit();
            return response([
                'data' => true
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function redeemgiftcard(UserRedeemGiftCard $request)
    {
        DB::beginTransaction();

        try {
            $user = User::find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            $giftcard_input = $request->giftcard;
            $giftcard = Giftcard::where('code', $giftcard_input)->lockForUpdate()->first();

            if (!$giftcard) {
                abort(500, __('The gift card does not exist'));
            }

            $currentTime = time();
            if ($giftcard->started_at && $currentTime < $giftcard->started_at) {
                abort(500, __('The gift card is not yet valid'));
            }

            if ($giftcard->ended_at && $currentTime > $giftcard->ended_at) {
                abort(500, __('The gift card has expired'));
            }

            if ($giftcard->limit_use !== null) {
                if (!is_numeric($giftcard->limit_use) || $giftcard->limit_use <= 0) {
                    abort(500, __('The gift card usage limit has been reached'));
                }
            }

            $usedUserIds = $giftcard->used_user_ids ? json_decode($giftcard->used_user_ids, true) : [];
            if (!is_array($usedUserIds)) {
                $usedUserIds = [];
            }

            if (in_array($user->id, $usedUserIds)) {
                abort(500, __('The gift card has already been used by this user'));
            }

            $usedUserIds[] = $user->id;
            $giftcard->used_user_ids = json_encode($usedUserIds);

            $giftcardBalanceBefore = (int)$user->balance;
            switch ($giftcard->type) {
                case 1:
                    $user->balance += $giftcard->value;
                    break;
                case 2:
                    if ($user->expired_at !== null) {
                        if ($user->expired_at <= $currentTime) {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        } else {
                            $user->expired_at += $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                case 3:
                    $user->transfer_enable += $giftcard->value * 1073741824;
                    break;
                case 4:
                    $user->u = 0;
                    $user->d = 0;
                    break;
                case 5:
                    if ($user->plan_id == null || ($user->expired_at !== null && $user->expired_at < $currentTime)) {
                        $plan = Plan::where('id', $giftcard->plan_id)->first();
                        $user->plan_id = $plan->id;
                        $user->group_id = $plan->group_id;
                        $user->transfer_enable = $plan->transfer_enable * 1073741824;
                        $user->device_limit = $plan->device_limit;
                        $user->u = 0;
                        $user->d = 0;
                        if($giftcard->value == 0) {
                            $user->expired_at = null;
                        } else {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                default:
                    abort(500, __('Unknown gift card type'));
            }

            if ($giftcard->limit_use !== null) {
                $giftcard->limit_use -= 1;
            }

            if (!$user->save() || !$giftcard->save()) {
                throw new \Exception(__('Save failed'));
            }

            $redemption = GiftcardRedemption::create([
                'giftcard_id' => $giftcard->id,
                'user_id' => $user->id,
                'code_snapshot' => $giftcard->code,
                'name_snapshot' => $giftcard->name,
                'type' => $giftcard->type,
                'value' => $giftcard->value,
                'plan_id' => $giftcard->plan_id,
                'redeemed_at' => $currentTime,
            ]);

            if ((int)$giftcard->type === 1) {
                app(BalanceLedgerService::class)->record([
                    'user_id' => $user->id,
                    'type' => 'giftcard',
                    'amount' => (int)$giftcard->value,
                    'balance_before' => $giftcardBalanceBefore,
                    'balance_after' => (int)$user->balance,
                    'source_key' => 'giftcard_redemption:' . $redemption->id,
                    'source_type' => 'giftcard_redemption',
                    'source_id' => $redemption->id,
                    'description' => '礼品卡兑换余额',
                ]);
            }

            DB::commit();

            return response([
                'data' => true,
                'type' => $giftcard->type,
                'value' => $giftcard->value
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    private function maskGiftcardCode($code)
    {
        $length = strlen($code);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }
        if ($length <= 4) {
            return substr($code, 0, 1) . str_repeat('*', $length - 2) . substr($code, -1);
        }
        return substr($code, 0, 2) . str_repeat('*', $length - 4) . substr($code, -2);
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'email',
                'transfer_enable',
                'device_limit',
                'last_login_at',
                'created_at',
                'banned',
                'auto_renewal',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user['avatar_url'] = 'https://cravatar.cn/avatar/' . md5($user->email) . '?s=64&d=identicon';
        return response([
            'data' => $user
        ]);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            User::where('invite_user_id', $request->user['id'])
                ->count()
        ];
        return response([
            'data' => $stat
        ]);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'device_limit',
                'email',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, __('Subscription plan does not exist'));
            }
            ContentLocale::localize($user['plan'], ['name', 'content'], $request);
        }

        //统计在线设备
        $countalive = 0;
        $ips_array = Cache::get('ALIVE_IP_USER_' . $request->user['id']);
        if ($ips_array) {
            $countalive = $ips_array['alive_ip'];
        }
        $user['alive_ip'] = $countalive;

        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);

        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        $user['allow_new_period'] = config('v2board.allow_new_period', 0);
        return response([
            'data' => $user
        ]);
    }

    public function unbindTelegram(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!$user->update(['telegram_id' => null])) {
            abort(500, __('Unbind telegram failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        return response([
            'data' => Helper::getSubscribeUrl($user['token'])
        ]);
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'auto_renewal',
            'remind_expire',
            'remind_traffic'
        ]);

        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            abort(500, __('Save failed'));
        }

        return response([
            'data' => true
        ]);
    }

    public function transfer(UserTransfer $request)
    {
        DB::transaction(function () use ($request) {
            $user = User::where('id', $request->user['id'])->lockForUpdate()->first();
            if (!$user) abort(500, __('The user does not exist'));
            $amount = (int)$request->input('transfer_amount');
            if ($amount > $user->commission_balance) abort(500, __('Insufficient commission balance'));

            $before = (int)$user->commission_balance;
            $walletBefore = (int)$user->balance;
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $request->user['id'];
            $order->plan_id = 0;
            $order->period = 'deposit';
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $amount;
            $orderService->setOrderType($user);
            $orderService->setInvite($user);

            $user->commission_balance -= $amount;
            $user->balance += $amount;
            $order->status = 3;
            $order->total_amount = 0;
            $order->surplus_amount = $amount;
            $order->callback_no = Order::CALLBACK_COMMISSION_TRANSFER;
            if (!$order->save() || !$user->save()) abort(500, __('Transfer failed'));

            app(CommissionLedgerService::class)->record([
                'user_id' => $user->id,
                'type' => 'transfer_out',
                'amount' => -$amount,
                'balance_before' => $before,
                'balance_after' => (int)$user->commission_balance,
                'source_key' => 'commission_transfer:' . $order->id,
                'source_type' => 'order',
                'source_id' => $order->id,
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'description' => '佣金划转至钱包余额',
                'meta' => ['wallet_balance_after' => (int)$user->balance],
            ]);
            app(BalanceLedgerService::class)->record([
                'user_id' => $user->id,
                'type' => 'commission_transfer',
                'amount' => $amount,
                'balance_before' => $walletBefore,
                'balance_after' => (int)$user->balance,
                'source_key' => 'commission_transfer:' . $order->id,
                'source_type' => 'order',
                'source_id' => $order->id,
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'description' => '佣金划转至钱包余额',
            ]);
        });

        return response([
            'data' => true
        ]);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }
}
