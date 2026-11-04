<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const CALLBACK_COMMISSION_TRANSFER = '佣金划转 Commission transfer';

    protected $table = 'v2_order';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'surplus_order_ids' => 'array',
        'coupon_snapshot' => 'array',
        'flash_sale_snapshot' => 'array'
    ];
}
