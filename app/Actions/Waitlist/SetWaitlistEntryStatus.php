<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Domain\Waitlist\WaitlistStatus;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Rejects or parks an entry on the waiting list (ADR-038). Nobody is
 * mailed: a rejected entry is deleted by waitlist:prune, a parked one stays
 * in the queue for a later decision. An approved entry cannot be changed.
 */
class SetWaitlistEntryStatus
{
    /**
     * @throws ValidationException
     */
    public function __invoke(User $admin, WaitlistEntry $entry, WaitlistStatus $status, ?string $note = null): void
    {
        HqAccess::ensure($admin);

        if ($status !== WaitlistStatus::Rejected && $status !== WaitlistStatus::Parked) {
            throw new InvalidArgumentException('Only REJECTED and PARKED are set here; approval has its own action.');
        }

        DB::transaction(function () use ($admin, $entry, $status, $note): void {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! $entry->status->isOpen()) {
                throw ValidationException::withMessages(['entry' => __('Anmälan är redan :status.', ['status' => mb_strtolower($entry->status->label())])]);
            }

            $from = $entry->status;

            $entry->update([
                'status' => $status,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => ApproveWaitlistEntry::note($note),
            ]);

            Audit::instance($status === WaitlistStatus::Rejected ? 'waitlist.rejected' : 'waitlist.parked', $entry, ['from' => $from->value], $admin);
        });
    }
}
