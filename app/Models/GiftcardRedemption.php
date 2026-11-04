<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftcardRedemption extends Model
{
    protected $table = 'v2_giftcard_redemption';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
