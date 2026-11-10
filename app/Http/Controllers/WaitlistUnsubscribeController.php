<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Inga fler påminnelser" (ADR-038 addendum): the link in the onboarding
 * mails. The route is signed, so the link itself is the proof. A GET only
 * shows a button, since mail scanners follow links; the POST of that button,
 * and the one-click POST of a mail client (RFC 8058), does the opt-out.
 * Nothing is shown about the entry, and a link for an entry that is gone gets
 * the same answer, so it reveals nothing. The audit row is written without the
 * visitor's IP address and user agent: the visitor is not a user of the
 * instance and the log is append-only.
 */
class WaitlistUnsubscribeController
{
    public function show(Request $request, int $entry): View
    {
        return view('start.reminders-confirm', ['action' => $request->fullUrl()]);
    }

    public function stop(int $entry): View
    {
        $found = WaitlistEntry::query()->find($entry);

        if ($found !== null && $found->reminders_opted_out_at === null) {
            $found->forceFill(['reminders_opted_out_at' => now()])->save();
            Audit::withoutRequest(fn () => Audit::instance('waitlist.reminders_opted_out', $found, ['entry_id' => $found->id]));
        }

        return view('start.reminders-stopped');
    }
}
