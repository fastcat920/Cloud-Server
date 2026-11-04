<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\DepositOrderPresenter;
use Tests\TestCase;

class DepositOrderPresenterTest extends TestCase
{
    public function testCommissionTransferUsesSurplusAmountWithoutDepositBonus()
    {
        config(['v2board.deposit_bounus' => ['10:5']]);
        $order = new Order([
            'plan_id' => 0,
            'period' => 'deposit',
            'total_amount' => 0,
            'surplus_amount' => 3000,
            'callback_no' => Order::CALLBACK_COMMISSION_TRANSFER,
        ]);

        $result = (new DepositOrderPresenter())->decorate($order);

        $this->assertSame('commission_transfer', $result->deposit_source);
        $this->assertSame(0, $result->bounus);
        $this->assertSame(3000, $result->get_amount);
    }

    public function testOnlineDepositKeepsConfiguredBonusCalculation()
    {
        config(['v2board.deposit_bounus' => ['10:2', '30:5']]);
        $order = new Order([
            'plan_id' => 0,
            'period' => 'deposit',
            'total_amount' => 3000,
            'surplus_amount' => 0,
            'callback_no' => 'payment-reference',
        ]);

        $result = (new DepositOrderPresenter())->decorate($order);

        $this->assertSame('online_deposit', $result->deposit_source);
        $this->assertSame(500, $result->bounus);
        $this->assertSame(3500, $result->get_amount);
    }
}
