<?php

namespace App\Services;

use App\Models\Order;

class DepositOrderPresenter
{
    public function decorate(Order $order): Order
    {
        $order->setAttribute('plan', ['id' => 0, 'name' => 'deposit']);

        if ($this->isCommissionTransfer($order)) {
            $order->setAttribute('deposit_source', 'commission_transfer');
            $order->setAttribute('bounus', 0);
            $order->setAttribute('get_amount', (int)$order->surplus_amount);
            return $order;
        }

        $bonus = $this->getBonus((int)$order->total_amount);
        $order->setAttribute('deposit_source', 'online_deposit');
        $order->setAttribute('bounus', $bonus);
        $order->setAttribute('get_amount', (int)$order->total_amount + $bonus);
        return $order;
    }

    private function isCommissionTransfer(Order $order): bool
    {
        return $order->period === 'deposit'
            && $order->callback_no === Order::CALLBACK_COMMISSION_TRANSFER
            && (int)$order->surplus_amount > 0;
    }

    private function getBonus(int $totalAmount): int
    {
        $tiers = config('v2board.deposit_bounus', []);
        if (empty($tiers) || $tiers[0] === null) return 0;

        $bonus = 0;
        foreach ($tiers as $tier) {
            list($amount, $tierBonus) = explode(':', $tier);
            if ($totalAmount >= (int)((float)$amount * 100)) {
                $bonus = max($bonus, (int)((float)$tierBonus * 100));
            }
        }
        return $bonus;
    }
}
