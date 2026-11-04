<?php

namespace App\Models;

use App\Support\FileStore\Model;

class ClientStorageTarget extends Model
{
    use UsesCloudAccount;
    protected $table = 'v2_client_storage_target';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'is_primary' => 'boolean', 'extra_json' => 'array'];
    protected $hidden = ['access_key_id_encrypted', 'secret_key_encrypted', 'security_token_encrypted'];

    public function publications()
    {
        return $this->hasMany(ClientConfigPublication::class, 'target_id');
    }
}
