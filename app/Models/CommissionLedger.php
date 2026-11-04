<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionLedger extends Model
{
    protected $table = 'v2_commission_ledger';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'meta' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
