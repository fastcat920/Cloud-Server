<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralVisit extends Model
{
    public $timestamps = false;
    protected $table = 'v2_referral_visit';
    protected $guarded = ['id'];
}
