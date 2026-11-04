<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlashSaleCampaign extends Model
{
    protected $table = 'v2_flash_sale_campaign';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'plan_ids' => 'array',
        'periods' => 'array',
        'allow_coupon' => 'boolean',
        'allow_member_discount' => 'boolean',
    ];
}
