<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrialClaim extends Model
{
    protected $table = 'v2_trial_claim';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
}
