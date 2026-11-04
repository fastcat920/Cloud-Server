<?php

namespace App\Models;

use App\Support\FileStore\Model;

class ClientConfigSetting extends Model
{
    protected $table = 'v2_client_config_setting';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['legacy_xor_key_encrypted', 'signing_private_key_encrypted'];

    public static function current(): self
    {
        return static::firstOrCreate([], ['encryption_mode' => 'xor_base64']);
    }
}
