<?php

namespace App\Models;

use App\Support\FileStore\Model;

class ClientConfigPublication extends Model
{
    protected $table = 'v2_client_config_publication';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public function config()
    {
        return $this->belongsTo(ClientRemoteConfig::class, 'config_id');
    }

    public function target()
    {
        return $this->belongsTo(ClientStorageTarget::class, 'target_id');
    }
}
