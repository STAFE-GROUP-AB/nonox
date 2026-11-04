<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Calendar\CalendarDocument;
use App\Modules\Crm\Calendar\CalendarEntries;
use App\Modules\Crm\Calendar\CalendarEntry;
use App\Modules\Crm\Calendar\MeetingInvitationMail;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\WinBackBlock;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * "Skicka inbjudan" (ADR-049): e-mails a planned meeting to its CRM person
 * as a calendar invitation (an .ics file, METHOD:REQUEST), from NONOX with
 * the user as organiser and Reply-To, so the answer reaches the user. Only
 * on purpose, per meeting.
 *
 * Refused for anyone who must not be contacted: a person marked Kontakta
 * inte (objection or restriction), an address on the suppression list, a
 * customer whose processing is restricted or who is anonymised or inactive
 * in the register, and a private person's account with someone marked
 * Kontakta inte (WinBackBlock, the same rule as a win-back reminder).
 *
 * The invitation carries the meeting's subject, time and place and the
 * sender's name and company, never the activity's own text: that is the
 * company's internal note. Sending again after the meeting moved sends
 * the higher SEQUENCE, which replaces the copy in the receiver's calendar.
 * Each invitation is logged as a done e-mail activity on the account and
 * audited with ids.
 */
final class SendMeetingInvitation
{
    public function __invoke(User $user, Company $company, Activity $meeting): Activity
    {
        Gate::forUser($user)->authorize('update', $meeting);

        return CurrentCompany::run($company, fn (): Activity => DB::transaction(function () use ($user, $company, $meeting): Activity {
            /** @var Activity $meeting */
            $meeting = Activity::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();

            if ($meeting->type !== ActivityType::Meeting || ! $meeting->isOpen() || $meeting->due_at === null) {
                throw ValidationException::withMessages(['invitation' => __('Bara ett planerat möte med en tid kan skickas som inbjudan.')]);
            }

            $person = $meeting->person_id === null ? null : Person::query()->find($meeting->person_id);

            if (! $person instanceof Person || blank($person->email)) {
                throw ValidationException::withMessages(['invitation' => __('Välj en person med e-postadress för mötet först.')]);
            }

            if ($person->doNotContact() || SuppressionList::match($person->email, $person->phone, $person->mobile)) {
                throw ValidationException::withMessages(['invitation' => __('Personen har bett att inte bli kontaktad. Ingen inbjudan kan skickas.')]);
            }

            /** @var Account $account */
            $account = Account::query()->findOrFail($meeting->account_id);

            if (WinBackBlock::for($account) !== null) {
                throw ValidationException::withMessages(['invitation' => __('Kunden får inte kontaktas just nu (begränsad behandling, inaktiv i kundregistret eller Kontakta inte). Ingen inbjudan kan skickas.')]);
            }

            if (blank($user->email)) {
                throw ValidationException::withMessages(['invitation' => __('Ditt konto saknar e-postadress.')]);
            }

            $companyName = (string) ($company->legal_name ?: $company->name);
            $entry = self::entry($meeting, $companyName, $user);
            $ics = CalendarDocument::invitation($entry, (string) $user->name, (string) $user->email, $person->name, (string) $person->email);

            $meeting->forceFill(['invited_at' => CarbonImmutable::now()])->save();

            $log = Activity::create([
                'account_id' => $meeting->account_id,
                'person_id' => $person->id,
                'deal_id' => $meeting->deal_id,
                'type' => ActivityType::Email,
                'subject' => __('Inbjudan till möte: :subject', ['subject' => $entry->summary]),
                'assigned_to' => $user->id,
                'done_at' => CarbonImmutable::now(),
                'created_by' => $user->id,
            ]);

            LogActivity::touch($log);
            Audit::record('crm.meeting_invitation_sent', $meeting, ['person_id' => $person->id, 'activity_id' => $log->id, 'sequence' => $meeting->calendar_sequence], $company, $user);

            // Sent last: if the mail cannot be handed over, nothing above is kept.
            Mail::to((string) $person->email, $person->name)->send(new MeetingInvitationMail($entry, $ics, $companyName, (string) $user->name, (string) $user->email));

            return $log;
        }));
    }

    /** What the receiver sees: the subject (or "Möte med <company>"), the time and the place, never the internal text. */
    public static function entry(Activity $meeting, string $companyName, User $user): CalendarEntry
    {
        /** @var CarbonImmutable $start */
        $start = $meeting->due_at;
        $subject = trim($meeting->subject);

        return new CalendarEntry(
            activityId: $meeting->id,
            uid: CalendarEntries::uid($meeting->id),
            sequence: $meeting->calendar_sequence,
            start: $start,
            end: $start->addMinutes($meeting->duration_minutes ?? CalendarEntries::defaultMinutes(ActivityType::Meeting)),
            summary: $subject === '' || $subject === ActivityType::Meeting->label() ? __('Möte med :company', ['company' => $companyName]) : $subject,
            description: __('Inbjudan från :name, :company.', ['name' => $user->name, 'company' => $companyName]),
            location: $meeting->location,
            url: null,
            lastModified: $meeting->updated_at ?? $start,
        );
    }
}
