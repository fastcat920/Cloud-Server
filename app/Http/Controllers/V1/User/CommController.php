<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CommController extends Controller
{
    public function config()
    {
        return response([
            'data' => [
                'is_telegram' => (int)config('v2board.telegram_bot_enable', 0),
                'telegram_discuss_link' => config('v2board.telegram_discuss_link'),
                'stripe_pk' => config('v2board.stripe_pk_live'),
                'withdraw_methods' => config('v2board.commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
                'withdraw_close' => (int)config('v2board.withdraw_close_enable', 0),
                'currency' => config('v2board.currency', 'CNY'),
                'currency_symbol' => config('v2board.currency_symbol', '¥'),
                 // 提供给客户端的充值金额和奖励配置
                'deposit_bounus' => config('v2board.deposit_bounus', []),
                'commission_distribution_enable' => (int)config('v2board.commission_distribution_enable', 0),
                'commission_distribution_l1' => config('v2board.commission_distribution_l1'),
                'commission_distribution_l2' => config('v2board.commission_distribution_l2'),
                'commission_distribution_l3' => config('v2board.commission_distribution_l3'),
                'features' => [
                    'coupon_wallet' => Schema::hasTable('v2_user_coupon'),
                    'order_preview' => true,
                    'flash_sale' => Schema::hasTable('v2_flash_sale_campaign'),
                    'referral_program' => Schema::hasTable('v2_referral_reward'),
                    'balance_ledger' => Schema::hasTable('v2_balance_ledger'),
                    'commission_ledger' => Schema::hasTable('v2_commission_ledger'),
                ],
            ]
        ]);
    }

    public function getStripePublicKey(Request $request)
    {
        $payment = Payment::where('id', $request->input('id'))
            ->where('payment', 'StripeCredit')
            ->first();
        if (!$payment) abort(500, __('Payment method is not available'));
        return response([
            'data' => $payment->config['stripe_pk_live']
        ]);
    }
}
