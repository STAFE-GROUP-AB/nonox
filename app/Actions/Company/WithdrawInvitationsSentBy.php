<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws the pending invitations a person sent to a company, when they are
 * removed from it or lose members:manage. Otherwise someone could invite a
 * second address of their own just before being removed, and come back in by
 * accepting it (security review 2026-11-01).
 */
class WithdrawInvitationsSentBy
{
    /**
     * @return int the number of invitations withdrawn
     */
    public function __invoke(Company $company, User $sender, string $reason): int
    {
        return DB::transaction(function () use ($company, $sender, $reason): int {
            $invitations = TeamInvitation::query()
                ->where('team_id', $company->id)
                ->where('invited_by', $sender->id)
                ->lockForUpdate()
                ->get();

            foreach ($invitations as $invitation) {
                $invitation->delete();

                Audit::record('company.invitation_withdrawn', null, [
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'invited_by' => $sender->id,
                    'reason' => $reason,
                ], $company);
            }

            return $invitations->count();
        });
    }
}
