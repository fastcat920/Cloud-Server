<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BalanceLedger extends Model
{
    protected $table = 'v2_balance_ledger';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'meta' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
