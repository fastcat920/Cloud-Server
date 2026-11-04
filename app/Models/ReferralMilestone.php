<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralMilestone extends Model
{
    protected $table = 'v2_referral_milestone';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public function level()
    {
        return $this->belongsTo(ReferralLevel::class, 'referral_level_id');
    }
}
