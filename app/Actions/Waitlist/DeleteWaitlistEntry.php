<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Hq\ReleaseWaitlistProspect;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an entry and its answers for good, for example when the applicant
 * asks for it (GDPR art. 17, ADR-038). A user created by an approval is left
 * alone: that is an account, deleted from the profile like any other.
 * The CRM prospect the entry became in the operator company follows the
 * rules of ReleaseWaitlistProspect (ADR-044).
 */
class DeleteWaitlistEntry
{
    public function __construct(private readonly ReleaseWaitlistProspect $release) {}

    /**
     * @return array{erased_accounts: int, erased_people: int, kept: int} what happened to the CRM prospect made from the entry (ADR-044)
     */
    public function __invoke(User $admin, WaitlistEntry $entry, string $reason = 'request'): array
    {
        HqAccess::ensure($admin);

        return DB::transaction(function () use ($admin, $entry, $reason): array {
            Audit::instance('waitlist.deleted', $entry, ['status' => $entry->status->value, 'reason' => $reason], $admin);

            // A deletion on the applicant's request erases their CRM person too (art. 17); see ReleaseWaitlistProspect.
            $released = ($this->release)($entry->id, request: $reason === 'request');

            WaitlistEntry::forgetNotices([$entry->id]);
            $entry->delete();

            return $released;
        });
    }
}
