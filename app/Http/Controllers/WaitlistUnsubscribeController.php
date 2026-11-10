<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use Illuminate\View\View;

/**
 * "Inga fler påminnelser" (ADR-038 addendum): the one-click link in the
 * onboarding mails. The route is signed, so the link itself is the proof;
 * nothing is asked and nothing is shown about the entry. A link for an entry
 * that is gone gets the same answer, so it reveals nothing. The mail clients'
 * one-click button posts to the same address.
 */
class WaitlistUnsubscribeController
{
    public function __invoke(int $entry): View
    {
        $found = WaitlistEntry::query()->find($entry);

        if ($found !== null && $found->reminders_opted_out_at === null) {
            $found->forceFill(['reminders_opted_out_at' => now()])->save();
            Audit::instance('waitlist.reminders_opted_out', $found, ['entry_id' => $found->id]);
        }

        return view('start.reminders-stopped');
    }
}
