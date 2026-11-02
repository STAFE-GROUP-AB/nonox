<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an entry and its answers for good, for example when the applicant
 * asks for it (GDPR art. 17, ADR-038). A user created by an approval is left
 * alone: that is an account, deleted from the profile like any other.
 */
class DeleteWaitlistEntry
{
    public function __invoke(User $admin, WaitlistEntry $entry, string $reason = 'request'): void
    {
        HqAccess::ensure($admin);

        DB::transaction(function () use ($admin, $entry, $reason): void {
            Audit::instance('waitlist.deleted', $entry, ['status' => $entry->status->value, 'reason' => $reason], $admin);

            WaitlistEntry::forgetNotices([$entry->id]);
            $entry->delete();
        });
    }
}
