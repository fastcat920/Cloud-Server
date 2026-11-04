<?php

namespace App\Services;

use App\Models\TrialClaim;
use Illuminate\Database\QueryException;
use RuntimeException;

class TrialClaimService
{
    public function hashEmail(string $email): string
    {
        $key = (string)config('account.trial_identity_key', '');
        if (strlen($key) < 32) {
            throw new RuntimeException('TRIAL_IDENTITY_KEY must contain at least 32 characters.');
        }

        return hash_hmac('sha256', strtolower(trim($email)), $key);
    }

    public function claim(string $email, ?int $userId = null): bool
    {
        $now = time();
        try {
            TrialClaim::create([
                'email_hash' => $this->hashEmail($email),
                'user_id' => $userId,
                'claimed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return true;
        } catch (QueryException $e) {
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
            if ($userId !== null) {
                TrialClaim::where('email_hash', $this->hashEmail($email))->update([
                    'user_id' => $userId,
                    'updated_at' => $now,
                ]);
            }
            return false;
        }
    }
}
