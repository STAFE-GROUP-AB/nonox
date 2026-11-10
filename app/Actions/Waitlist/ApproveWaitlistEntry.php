<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Domain\Waitlist\WaitlistStatus;
use App\Mail\WaitlistApprovedMail;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Lets someone on the waiting list in (ADR-038): creates their user, so the
 * sign-in form will send them a code, and mails them that they may log in.
 * The address is confirmed by the first code they enter. Their company is
 * created by themselves on /kom-igang after the first sign-in.
 */
class ApproveWaitlistEntry
{
    /**
     * @throws ValidationException
     */
    public function __invoke(User $admin, WaitlistEntry $entry, ?string $note = null): User
    {
        HqAccess::ensure($admin);

        $sendWelcome = true;

        $user = DB::transaction(function () use ($admin, $entry, $note, &$sendWelcome): User {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! $entry->status->isOpen()) {
                throw ValidationException::withMessages(['entry' => __('Anmälan är redan :status.', ['status' => mb_strtolower($entry->status->label())])]);
            }

            $existing = User::query()->byEmail($entry->email)->orderBy('id')->first();
            $user = $existing ?? User::create(['name' => $entry->name, 'email' => $entry->email, 'email_verified_at' => null]);

            $entry->update([
                'status' => WaitlistStatus::Approved,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => self::note($note),
                'approved_user_id' => $user->id,
                'approved_user_existed' => $existing !== null,
            ]);

            // One welcome mail per user: an earlier approved entry of the same user has already sent it.
            $sendWelcome = ! WaitlistEntry::query()
                ->where('approved_user_id', $user->id)
                ->where('status', WaitlistStatus::Approved->value)
                ->whereKeyNot($entry->id)
                ->exists();

            // No personal details in the append-only audit log: the entry itself can be deleted later.
            Audit::instance('waitlist.approved', $entry, ['user_id' => $user->id, 'user_created' => $existing === null], $admin);

            return $user;
        });

        if ($sendWelcome) {
            Mail::to($user->email)->queue(new WaitlistApprovedMail($entry));
        }

        return $user;
    }

    public static function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 2000);
    }
}
