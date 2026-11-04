<?php

namespace App\Services;

use App\Models\BalanceLedger;
use Illuminate\Support\Facades\Schema;

class BalanceLedgerService
{
    public function record(array $data): ?BalanceLedger
    {
        if (!Schema::hasTable('v2_balance_ledger')) return null;

        return BalanceLedger::firstOrCreate(
            ['source_key' => $data['source_key']],
            [
                'user_id' => $data['user_id'],
                'type' => $data['type'],
                'amount' => $data['amount'],
                'balance_before' => $data['balance_before'] ?? null,
                'balance_after' => $data['balance_after'] ?? null,
                'status' => $data['status'] ?? 'completed',
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'order_id' => $data['order_id'] ?? null,
                'trade_no' => $data['trade_no'] ?? null,
                'description' => $data['description'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]
        );
    }
}
