<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponWalletService;
use App\Services\FlashSaleService;
use App\Services\DepositOrderPresenter;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Services\BalanceLedgerService;
use App\Support\ContentLocale;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function preview(Request $request, CouponWalletService $couponService, FlashSaleService $flashSaleService)
    {
        $data = $request->validate([
            'plan_id' => 'required|integer',
            'period' => 'required|string',
            'user_coupon_id' => 'nullable|integer',
            'disable_auto_coupon' => 'nullable|boolean',
            'promotion_mode' => 'nullable|in:auto,flash_sale,coupon,standard',
        ]);
        $plan = Plan::findOrFail($data['plan_id']);
        if (!array_key_exists($data['period'], $plan->getAttributes()) || $plan[$data['period']] === null) {
            abort(422, '当前付款周期不可购买');
        }

        $user = User::findOrFail($request->user['id']);
        $planAmount = (int)$plan[$data['period']];
        $order = new Order([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => $data['period'],
            'total_amount' => $planAmount,
        ]);
        (new OrderService($order))->setOrderType($user);
        $surplusAmount = (int)$order->surplus_amount;
        $refundAmount = (int)$order->refund_amount;

        $flashSale = $flashSaleService->match($user, (int)$plan->id, $data['period'], (int)$order->total_amount, (int)$order->type);
        if ($flashSale && !$flashSale->allow_coupon) {
            $selection = $this->selectExclusivePromotion($order, $user, $flashSale, $couponService, $flashSaleService, $request);
            $selected = $selection['selected'];
            $balanceAmount = min((int)$user->balance, (int)$selected['final_amount']);
            return response(['data'=>[
                'original_amount'=>$planAmount,
                'surplus_amount'=>$surplusAmount,
                'refund_amount'=>$refundAmount,
                'activity_discount'=>$selected['activity_discount'],
                'flash_sale'=>$selected['type'] === 'flash_sale' ? $flashSale : null,
                'allow_coupon'=>false,
                'allow_member_discount'=>(bool)$flashSale->allow_member_discount,
                'coupon_discount'=>$selected['coupon_discount'],
                'member_discount_rate'=>$selection['member_discount_rate'],
                'member_discount'=>$selected['member_discount'],
                'vip_discount'=>$selected['member_discount'],
                'final_amount'=>$selected['final_amount'],
                'balance_amount'=>$balanceAmount,
                'payable_amount'=>max(0,(int)$selected['final_amount']-$balanceAmount),
                'selected_coupon'=>$selected['coupon'],
                'available_coupons'=>$selection['available'],
                'unavailable_coupons'=>$selection['unavailable'],
                'promotion_exclusive'=>true,
                'promotion_options'=>$selection['options'],
                'selected_promotion'=>$selection['selected_public'],
            ]]);
        }
        if ($flashSale) $flashSaleService->applyCampaign($order, $flashSale);
        $activityDiscount = (int)$order->flash_sale_discount_amount;
        $couponBase = (int)$order->total_amount;
        $available = $couponService->available($user, $plan->id, $data['period'], $couponBase, (int)$order->type);
        $selected = $request->boolean('disable_auto_coupon')
            ? null
            : ($request->input('user_coupon_id')
                ? $available->firstWhere('id', (int)$request->input('user_coupon_id'))
                : $available->first());
        if ($request->input('user_coupon_id') && !$selected) abort(422, '所选优惠券不满足使用条件');

        $couponDiscount = $selected ? (int)$selected->calculated_discount : 0;
        $afterCoupon = $couponBase - $couponDiscount;
        $memberDiscountRate = app(\App\Services\ReferralProgramService::class)->memberDiscountRate($user);
        $memberDiscountAllowed = !$flashSale || (bool)$flashSale->allow_member_discount;
        $memberDiscount = $memberDiscountAllowed && (!$selected || $selected->template->stackable) && $memberDiscountRate
            ? (int)round($afterCoupon * $memberDiscountRate / 100)
            : 0;
        $unavailable = $couponService->unavailable($user, $plan->id, $data['period'], $couponBase, (int)$order->type);
        $orderAmount = max(0, $afterCoupon - $memberDiscount);
        $balanceAmount = min((int)$user->balance, $orderAmount);
        return response(['data'=>[
            'original_amount'=>$planAmount,
            'surplus_amount'=>$surplusAmount,
            'refund_amount'=>$refundAmount,
            'activity_discount'=>$activityDiscount,
            'flash_sale'=>$flashSale,
            'allow_coupon'=>!$flashSale||(bool)$flashSale->allow_coupon,
            'allow_member_discount'=>$memberDiscountAllowed,
            'coupon_discount'=>$couponDiscount,
            'member_discount_rate'=>$memberDiscountRate,
            'member_discount'=>$memberDiscount,
            'vip_discount'=>$memberDiscount,
            'final_amount'=>$orderAmount,
            'balance_amount'=>$balanceAmount,
            'payable_amount'=>max(0,$orderAmount-$balanceAmount),
            'selected_coupon'=>$selected,
            'available_coupons'=>$available,
            'unavailable_coupons'=>$unavailable,
            'promotion_exclusive'=>false,
            'promotion_options'=>[],
            'selected_promotion'=>null,
        ]]);
    }

    public function fetch(Request $request)
    {
        $model = Order::where('user_id', $request->user['id'])
            ->orderBy('created_at', 'DESC');
        if ($request->input('status') !== null) {
            $model->where('status', $request->input('status'));
        }
        $order = $model->get();
        $plan = Plan::get();
        $plan->each(function ($item) use ($request) {
            ContentLocale::localize($item, ['name', 'content'], $request);
        });
        for ($i = 0; $i < count($order); $i++) {
            for ($x = 0; $x < count($plan); $x++) {
                if ($order[$i]['plan_id'] === $plan[$x]['id']) {
                    $order[$i]['plan'] = $plan[$x];
                }
            }
        }
        return response([
            'data' => $order->makeHidden(['id', 'user_id'])
        ]);
    }

    public function detail(Request $request, DepositOrderPresenter $depositPresenter)
    {
        $order = Order::where('user_id', $request->user['id'])
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        if ($order->plan_id == 0) {
            return response([
                'data' => $depositPresenter->decorate($order)
            ]);
        }
        $order['plan'] = Plan::find($order->plan_id);
        $order['try_out_plan_id'] = (int)config('v2board.try_out_plan_id');
        if (!$order['plan']) {
            abort(500, __('Subscription plan does not exist'));
        }
        ContentLocale::localize($order['plan'], ['name', 'content'], $request);
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return response([
            'data' => $order
        ]);
    }

    public function save(OrderSave $request)
    {
        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($request->user['id'])) {
            abort(500, __('You have an unpaid or pending order, please try again later or cancel it'));
        }
        if ($request->input('plan_id') == 0) {
            $amount = $request->input('deposit_amount');
            if ($amount <= 0) {
                abort(500, __('Failed to create order, deposit amount must be greater than 0'));
            }
            if ($amount >= 9999999 ) {
                abort(500, __('Deposit amount too large, please contact the administrator'));
            }
            $user = User::find($request->user['id']);
            DB::beginTransaction();
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $request->user['id'];
            $order->plan_id = $request->input('plan_id');
            $order->period = 'deposit';
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $amount;
            
            $orderService->setOrderType($user);
            $orderService->setInvite($user);

            if (!$order->save()) {
                DB::rollback();
                abort(500, __('Failed to create order'));
            }
    
            DB::commit();
    
            return response([
                'data' => $order->trade_no
            ]);
        }
        $planService = new PlanService($request->input('plan_id'));

        $plan = $planService->plan;
        $user = User::find($request->user['id']);

        if (!$plan) {
            abort(500, __('Subscription plan does not exist'));
        }

        if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && $request->input('period') !== 'reset_price') {
            abort(500, __('Current product is sold out'));
        }

        if ($plan[$request->input('period')] === NULL) {
            abort(500, __('This payment period cannot be purchased, please choose another period'));
        }

        if ($request->input('period') === 'reset_price') {
            if (!$userService->isAvailable($user) || $plan->id !== $user->plan_id) {
                abort(500, __('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
            }
        }

        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            if ($request->input('period') !== 'reset_price') {
                abort(500, __('This subscription has been sold out, please choose another subscription'));
            }
        }

        if (!$plan->renew && $user->plan_id == $plan->id && $request->input('period') !== 'reset_price') {
            abort(500, __('This subscription cannot be renewed, please change to another subscription'));
        }


        if (!$plan->show && $plan->renew && !$userService->isAvailable($user)) {
            abort(500, __('This subscription has expired, please change to another subscription'));
        }

        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $request->user['id'];
        $order->plan_id = $plan->id;
        $order->period = $request->input('period');
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $plan[$request->input('period')];

        $orderService->setOrderType($user);
        $flashSaleService = app(FlashSaleService::class);
        $couponService = app(CouponWalletService::class);
        $flashSale = $flashSaleService->match($user, (int)$plan->id, (string)$order->period, (int)$order->total_amount, (int)$order->type);
        $userCoupon = null;
        if ($flashSale && !$flashSale->allow_coupon) {
            $selection = $this->selectExclusivePromotion($order, $user, $flashSale, $couponService, $flashSaleService, $request);
            $selected = $selection['selected'];
            if ($selected['type'] === 'flash_sale') {
                $flashSaleService->applyCampaign($order, $flashSale);
                if ($flashSale->allow_member_discount) $orderService->setVipDiscount($user);
            } elseif ($selected['type'] === 'coupon') {
                $userCoupon = $couponService->lockForOrder($order, $user, (int)$selected['coupon_id'], false);
                if ($userCoupon && $userCoupon->template->stackable) $orderService->setVipDiscount($user);
                $flashSale = null;
            } else {
                $orderService->setVipDiscount($user);
                $flashSale = null;
            }
        } else {
            if ($flashSale) $flashSaleService->applyCampaign($order, $flashSale);
            $userCoupon = $couponService->lockForOrder($order, $user, $request->input('user_coupon_id') ? (int)$request->input('user_coupon_id') : null, $request->boolean('disable_auto_coupon'));
            if ((!$flashSale || $flashSale->allow_member_discount) && (!$userCoupon || $userCoupon->template->stackable)) $orderService->setVipDiscount($user);
        }

        $balanceBefore = (int)$user->balance;
        if ($user->balance > 0 && $order->total_amount > 0) {
            $remainingBalance = $user->balance - $order->total_amount;
            $userService = new UserService();
            if ($remainingBalance > 0) {
                if (!$userService->addBalance($order->user_id, - $order->total_amount)) {
                    DB::rollBack();
                    abort(500, __('Insufficient balance'));
                }
                $order->balance_amount = $order->total_amount;
                $order->total_amount = 0;
            } else {
                if (!$userService->addBalance($order->user_id, - $user->balance)) {
                    DB::rollBack();
                    abort(500, __('Insufficient balance'));
                }
                $order->balance_amount = $user->balance;
                $order->total_amount -= $user->balance;
            }
        }

        $orderService->setInvite($user);

        if (!$order->save()) {
            DB::rollback();
            abort(500, __('Failed to create order'));
        }

        if ((int)$order->balance_amount > 0) {
            $balanceAfter = (int)User::where('id', $order->user_id)->value('balance');
            app(BalanceLedgerService::class)->record([
                'user_id' => $order->user_id,
                'type' => 'purchase',
                'amount' => -(int)$order->balance_amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'source_key' => 'purchase:' . $order->id,
                'source_type' => 'order',
                'source_id' => $order->id,
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'description' => '订单使用余额',
            ]);
        }

        DB::commit();

        return response([
            'data' => $order->trade_no
        ]);
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->where('status', 0)
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        // free process
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no)) abort(500, '');
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || $payment->enable !== 1) abort(500, __('Payment method is not available'));
        $paymentService = new PaymentService($payment->payment, $payment->id);
        $order->handling_amount = NULL;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $order->handling_amount = round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        $order->payment_id = $method;
        if (!$order->save()) abort(500, __('Request failed, please try again later'));
        $result = $paymentService->pay([
            'trade_no' => $tradeNo,
            'total_amount' => isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token')
        ], $this->getPaymentReturnUrl($request, $tradeNo));
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    private function getPaymentReturnUrl(Request $request, $tradeNo)
    {
        foreach ([$request->header('origin'), $request->header('referer')] as $source) {
            $origin = $this->normalizePaymentReturnOrigin($source);
            if ($origin) {
                return $origin . '/#/payment?trade_no=' . rawurlencode($tradeNo);
            }
        }

        $defaultOrigin = $this->normalizePaymentReturnOrigin(config('v2board.app_url'));
        if ($defaultOrigin) {
            return $defaultOrigin . '/#/payment?trade_no=' . rawurlencode($tradeNo);
        }

        return url('/#/payment?trade_no=' . rawurlencode($tradeNo));
    }

    private function normalizePaymentReturnOrigin($url)
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $origin = $scheme . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function selectExclusivePromotion(
        Order $order,
        User $user,
        $flashSale,
        CouponWalletService $couponService,
        FlashSaleService $flashSaleService,
        Request $request
    ): array {
        $baseAmount = max(0, (int)$order->total_amount);
        $memberDiscountRate = app(\App\Services\ReferralProgramService::class)->memberDiscountRate($user);
        $available = $couponService->available($user, (int)$order->plan_id, (string)$order->period, $baseAmount, (int)$order->type);
        $unavailable = $couponService->unavailable($user, (int)$order->plan_id, (string)$order->period, $baseAmount, (int)$order->type);
        $options = [];

        $flashQuote = $flashSaleService->quote($flashSale, $baseAmount);
        $flashMemberDiscount = $flashSale->allow_member_discount && $memberDiscountRate
            ? (int)round($flashQuote['final_amount'] * $memberDiscountRate / 100)
            : 0;
        $options[] = [
            'key'=>'flash_sale', 'type'=>'flash_sale', 'coupon_id'=>null, 'coupon'=>null,
            'name'=>$flashSale->name, 'name_en'=>$flashSale->name_en,
            'description'=>$flashSale->description, 'description_en'=>$flashSale->description_en,
            'activity_discount'=>(int)$flashQuote['discount_amount'], 'coupon_discount'=>0,
            'member_discount'=>$flashMemberDiscount,
            'discount_amount'=>(int)$flashQuote['discount_amount'] + $flashMemberDiscount,
            'final_amount'=>max(0, (int)$flashQuote['final_amount'] - $flashMemberDiscount),
            'sort_priority'=>0,
        ];

        $standardMemberDiscount = $memberDiscountRate ? (int)round($baseAmount * $memberDiscountRate / 100) : 0;
        $options[] = [
            'key'=>'standard', 'type'=>'standard', 'coupon_id'=>null, 'coupon'=>null,
            'name'=>null, 'name_en'=>null, 'description'=>null, 'description_en'=>null,
            'activity_discount'=>0, 'coupon_discount'=>0, 'member_discount'=>$standardMemberDiscount,
            'discount_amount'=>$standardMemberDiscount,
            'final_amount'=>max(0, $baseAmount - $standardMemberDiscount),
            'sort_priority'=>1,
        ];

        foreach ($available as $coupon) {
            $couponDiscount = (int)$coupon->calculated_discount;
            $afterCoupon = max(0, $baseAmount - $couponDiscount);
            $couponMemberDiscount = $coupon->template->stackable && $memberDiscountRate
                ? (int)round($afterCoupon * $memberDiscountRate / 100)
                : 0;
            $options[] = [
                'key'=>'coupon:'.$coupon->id, 'type'=>'coupon', 'coupon_id'=>(int)$coupon->id, 'coupon'=>$coupon,
                'name'=>$coupon->template->name, 'name_en'=>$coupon->template->name_en,
                'description'=>$coupon->template->description, 'description_en'=>$coupon->template->description_en,
                'activity_discount'=>0, 'coupon_discount'=>$couponDiscount,
                'member_discount'=>$couponMemberDiscount,
                'discount_amount'=>$couponDiscount + $couponMemberDiscount,
                'final_amount'=>max(0, $afterCoupon - $couponMemberDiscount),
                'sort_priority'=>2,
            ];
        }

        $ranked = collect($options)->sort(function ($left, $right) {
            if ($left['final_amount'] !== $right['final_amount']) return $left['final_amount'] <=> $right['final_amount'];
            if ($left['sort_priority'] !== $right['sort_priority']) return $left['sort_priority'] <=> $right['sort_priority'];
            return ($left['coupon_id'] ?? 0) <=> ($right['coupon_id'] ?? 0);
        })->values();
        $recommended = $ranked->first();

        $mode = (string)$request->input('promotion_mode', 'auto');
        if (!$request->has('promotion_mode') && $request->input('user_coupon_id')) $mode = 'coupon';
        if ($mode === 'coupon') {
            $couponId = (int)$request->input('user_coupon_id');
            $selected = collect($options)->first(function ($option) use ($couponId) {
                return $option['type'] === 'coupon' && $option['coupon_id'] === $couponId;
            });
            if (!$selected) abort(422, '所选优惠券不满足当前订单使用条件');
        } elseif ($mode === 'flash_sale') {
            $selected = collect($options)->firstWhere('type', 'flash_sale');
        } elseif ($mode === 'standard') {
            $selected = collect($options)->firstWhere('type', 'standard');
        } else {
            $candidates = $request->boolean('disable_auto_coupon')
                ? $ranked->where('type', '!=', 'coupon')->values()
                : $ranked;
            $selected = $candidates->first();
        }

        $publicOptions = collect($options)->map(function ($option) use ($recommended) {
            unset($option['coupon'], $option['sort_priority']);
            $option['recommended'] = $option['key'] === $recommended['key'];
            return $option;
        })->sort(function ($left, $right) {
            if ($left['final_amount'] !== $right['final_amount']) return $left['final_amount'] <=> $right['final_amount'];
            return $left['recommended'] ? -1 : ($right['recommended'] ? 1 : 0);
        })->values();
        $selectedPublic = $publicOptions->firstWhere('key', $selected['key']);

        return [
            'selected'=>$selected,
            'selected_public'=>$selectedPublic,
            'options'=>$publicOptions,
            'available'=>$available,
            'unavailable'=>$unavailable,
            'member_discount_rate'=>$memberDiscountRate,
        ];
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        return response([
            'data' => $order->status
        ]);
    }

    public function getPaymentMethod(Request $request)
    {
        $methods = Payment::select([
            'id',
            'name',
            'name_en',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        $methods->each(function ($method) use ($request) {
            ContentLocale::localize($method, ['name'], $request);
        });

        return response([
            'data' => $methods
        ]);
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            abort(500, __('Invalid parameter'));
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        if ($order->status !== 0) {
            abort(500, __('You can only cancel pending orders'));
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            abort(500, __('Cancel failed'));
        }
        return response([
            'data' => true
        ]);
    }

}
