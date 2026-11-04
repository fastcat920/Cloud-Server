<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralMaterial extends Model
{
    protected $table = 'v2_referral_material';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
}
