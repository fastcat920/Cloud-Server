<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralReward extends Model
{
    protected $table = 'v2_referral_reward';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['meta' => 'array'];
}
