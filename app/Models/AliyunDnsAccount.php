<?php

namespace App\Models;

use App\Support\FileStore\Model;

class AliyunDnsAccount extends Model
{
    use UsesCloudAccount;
    protected $table = 'v2_aliyun_dns_account';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'is_default' => 'boolean',
    ];
    protected $hidden = [
        'access_key_id_encrypted',
        'access_key_secret_encrypted',
        'security_token_encrypted',
    ];
}
