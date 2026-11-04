<?php

namespace App\Models;

use App\Support\FileStore\Model;

class User extends Model
{
    protected $hidden = ['password', 'token', 'password_salt'];
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'invite_commission_eligible' => 'boolean',
        'invitee_reward_eligible' => 'boolean',
    ];
}
