<?php

namespace App\Models;

use App\Support\FileStore\Model;

class CloudAccount extends Model
{
    protected $table = 'v2_cloud_account';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'balance' => 'float',
        'current_month_amount' => 'float',
        'previous_month_amount' => 'float',
    ];
    protected $hidden = [
        'access_key_id_encrypted',
        'access_key_secret_encrypted',
        'security_token_encrypted',
    ];
}
