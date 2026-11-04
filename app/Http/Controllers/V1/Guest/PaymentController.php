<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\StatisticalService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        if ($order->status !== 0) return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        $todayPaidStats = (new StatisticalService())->generatePaidStatData();
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款 %.2f 元\n订单号：%s\n———————————————\n今日支付订单：%d 笔\n今日总收入：%.2f 元",
            $order->total_amount / 100,
            $order->trade_no,
            $todayPaidStats['paid_count'],
            $todayPaidStats['paid_total'] / 100
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
