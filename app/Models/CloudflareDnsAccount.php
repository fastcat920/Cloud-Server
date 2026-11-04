<?php

namespace App\Models;

use App\Support\FileStore\Model;

class CloudflareDnsAccount extends Model
{
    protected $table = 'v2_cloudflare_dns_account';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'is_default' => 'boolean',
    ];
    protected $hidden = ['api_token_encrypted'];
}
