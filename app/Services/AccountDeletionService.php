<?php

namespace App\Services;

use App\Models\InviteCode;
use App\Models\Ticket;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountDeletionService
{
    private $trialClaims;

    public function __construct(TrialClaimService $trialClaims)
    {
        $this->trialClaims = $trialClaims;
    }

    public function anonymize(User $user, string $type, ?int $adminId = null, ?string $reason = null): void
    {
        if ($user->deleted_at) {
            return;
        }

        DB::transaction(function () use ($user, $type, $adminId, $reason) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if (!$lockedUser || $lockedUser->deleted_at) {
                return;
            }

            $emailHash = $this->trialClaims->hashEmail($lockedUser->email);
            $this->trialClaims->claim($lockedUser->email, (int)$lockedUser->id);
            (new AuthService($lockedUser))->removeAllSession();

            InviteCode::where('user_id', $lockedUser->id)->delete();
            $relationReset = ['invite_user_id' => null];
            foreach (['invite_commission_eligible', 'invitee_reward_eligible', 'invite_reward_evaluated_at'] as $column) {
                if (Schema::hasColumn('v2_user', $column)) $relationReset[$column] = null;
            }
            User::where('invite_user_id', $lockedUser->id)->update($relationReset);
            Ticket::where('user_id', $lockedUser->id)->where('status', 0)->update([
                'status' => 1,
                'updated_at' => time(),
            ]);

            $now = time();
            $lockedUser->email = 'deleted_' . $lockedUser->id . '_' . substr(Helper::guid(), 0, 12) . '@invalid.local';
            $lockedUser->password = password_hash(Helper::guid() . Helper::guid(), PASSWORD_DEFAULT);
            $lockedUser->password_algo = null;
            $lockedUser->password_salt = null;
            $lockedUser->telegram_id = null;
            $lockedUser->invite_user_id = null;
            if (Schema::hasColumn('v2_user', 'invite_commission_eligible')) $lockedUser->invite_commission_eligible = null;
            if (Schema::hasColumn('v2_user', 'invitee_reward_eligible')) $lockedUser->invitee_reward_eligible = null;
            if (Schema::hasColumn('v2_user', 'invite_reward_evaluated_at')) $lockedUser->invite_reward_evaluated_at = null;
            $lockedUser->last_login_ip = null;
            $lockedUser->token = Helper::guid();
            $lockedUser->uuid = Helper::guid(true);
            $lockedUser->plan_id = null;
            $lockedUser->group_id = null;
            $lockedUser->transfer_enable = 0;
            $lockedUser->device_limit = null;
            $lockedUser->speed_limit = null;
            $lockedUser->expired_at = $now;
            $lockedUser->auto_renewal = 0;
            $lockedUser->remind_expire = 0;
            $lockedUser->remind_traffic = 0;
            $lockedUser->is_staff = 0;
            $lockedUser->remarks = null;
            $lockedUser->banned = 1;
            $lockedUser->deleted_at = $now;
            $lockedUser->deletion_type = $type;
            $lockedUser->deletion_reason = $reason;
            $lockedUser->deleted_by_admin_id = $adminId;
            $lockedUser->save();

            DB::table('v2_account_deletion_log')->insert([
                'user_id' => $lockedUser->id,
                'email_hash' => $emailHash,
                'deletion_type' => $type,
                'admin_id' => $adminId,
                'reason' => $reason,
                'created_at' => $now,
            ]);
        });
    }
}
