<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\DoNotContact;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which CRM activities reach a calendar, and with how much (ADR-049).
 *
 * In a calendar: open calls, meetings and tasks with a time, assigned to the
 * user, from 30 days back to a year ahead (a deal's next step is one of
 * them). Done activities are left out, so a subscribed calendar drops them
 * on its next refresh. Never in a calendar: the activities of a person whose
 * processing is restricted (art. 18) or of a customer with restricted
 * processing: a calendar copy is processing beyond storage.
 *
 * How much: a subscription link is reachable without signing in, so by
 * default an event says only "Möte: <företag>" and links back to NONOX; a
 * private person's account is not named at all ("privatkund"). With the
 * details the user opted into: the subject, the place, the deal and the
 * activity's text and person. A person marked Kontakta inte is never named
 * and the text about them never copied. Nothing from the ledger is ever
 * put in a calendar, so a seller's limits on a private person's ledger
 * (ADR-043) are kept.
 */
final class CalendarEntries
{
    public const int DAYS_BACK = 30;

    public const int DAYS_AHEAD = 365;

    /** The activity types a calendar shows. */
    public const array TYPES = [ActivityType::Meeting, ActivityType::Call, ActivityType::Task];

    /** A planned activity's length when none is given. */
    public static function defaultMinutes(ActivityType $type): int
    {
        return $type === ActivityType::Meeting ? 60 : 15;
    }

    /** @return list<string> */
    public static function typeValues(): array
    {
        return array_map(fn (ActivityType $type): string => $type->value, self::TYPES);
    }

    /**
     * The user's events in the company, oldest first. A fixed number of
     * queries, whatever the number of activities.
     *
     * @return Collection<int, CalendarEntry>
     */
    public static function forUser(Company $company, User $user, bool $details, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        return CurrentCompany::run($company, fn (): Collection => self::eligible(Activity::query())
            ->where('assigned_to', $user->id)
            ->whereBetween('due_at', [$now->subDays(self::DAYS_BACK), $now->addDays(self::DAYS_AHEAD)])
            ->with(self::relations())
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(2000)
            ->get()
            ->map(fn (Activity $activity): CalendarEntry => self::entry($activity, $details))
            ->values());
    }

    /**
     * Activities that may be in a calendar at all: open, timed, of a
     * calendar type, and of nobody whose processing is restricted.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public static function eligible(Builder $query): Builder
    {
        return $query
            ->whereNull('done_at')
            ->whereNotNull('due_at')
            ->whereIn('type', self::typeValues())
            ->whereDoesntHave('person', fn (Builder $person) => $person->where('do_not_contact_reason', DoNotContact::Restriction->value))
            ->whereDoesntHave('account.customer', fn (Builder $customer) => $customer->whereNotNull('processing_restricted_at'))
            // A private person's account: the people at it are the customer, so a restriction of any of them keeps it out.
            ->whereDoesntHave('account', fn (Builder $account) => $account
                ->where('kind', AccountKind::Person->value)
                ->whereHas('people', fn (Builder $person) => $person->where('do_not_contact_reason', DoNotContact::Restriction->value)));
    }

    /** Whether this one activity may be in a calendar (with its relations loaded). */
    public static function allows(Activity $activity): bool
    {
        return $activity->isOpen()
            && $activity->due_at !== null
            && in_array($activity->type, self::TYPES, true)
            && $activity->person?->do_not_contact_reason !== DoNotContact::Restriction
            && $activity->account?->customer?->processing_restricted_at === null
            && ! ($activity->account?->kind === AccountKind::Person
                && $activity->account->flaggedPeople->contains(fn (Person $person): bool => $person->do_not_contact_reason === DoNotContact::Restriction));
    }

    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return [
            'account:id,company_id,name,kind,customer_id',
            'account.customer:id,company_id,processing_restricted_at',
            // The people marked Kontakta inte at the account: for a private person's account they decide what may be shown.
            'account.flaggedPeople:crm_people.id,crm_people.company_id,crm_people.do_not_contact_at,crm_people.do_not_contact_reason',
            'person:id,company_id,name,do_not_contact_at,do_not_contact_reason',
            'deal:id,company_id,title',
        ];
    }

    public static function entry(Activity $activity, bool $details): CalendarEntry
    {
        /** @var CarbonImmutable $start */
        $start = $activity->due_at;
        $minutes = $activity->duration_minutes ?? self::defaultMinutes($activity->type);
        $account = $activity->account;
        $isPrivate = $account?->kind === AccountKind::Person;
        $flagged = $activity->person?->doNotContact() ?? false;
        // A private person's account where someone is marked Kontakta inte: never named, no text, whatever the choice.
        $accountFlagged = $isPrivate && $account->flaggedPeople->isNotEmpty();
        $url = route('crm.accounts.show', $activity->account_id);

        if (! $details || $accountFlagged) {
            $who = $isPrivate || $account === null ? __('privatkund') : $account->name;

            return new CalendarEntry(
                activityId: $activity->id,
                uid: self::uid($activity->id),
                sequence: $activity->calendar_sequence,
                start: $start,
                end: $start->addMinutes($minutes),
                summary: __(':type: :who', ['type' => $activity->type->label(), 'who' => $who]),
                description: __('Detaljerna finns i NONOX: :url', ['url' => $url]),
                location: null,
                url: $url,
                lastModified: $activity->updated_at ?? $start,
            );
        }

        $lines = array_values(array_filter([
            $activity->type->label().($account === null ? '' : ' · '.$account->name),
            ! $flagged && $activity->person !== null ? self::say('Person: :name', ['name' => $activity->person->name]) : null,
            $activity->deal !== null ? self::say('Affär: :title', ['title' => $activity->deal->title]) : null,
            ! $flagged && filled($activity->body) ? "\n".$activity->body : null,
            "\n".self::say('Öppna i NONOX: :url', ['url' => $url]),
        ]));

        return new CalendarEntry(
            activityId: $activity->id,
            uid: self::uid($activity->id),
            sequence: $activity->calendar_sequence,
            start: $start,
            end: $start->addMinutes($minutes),
            summary: $account === null ? $activity->subject : "{$activity->subject} – {$account->name}",
            description: implode("\n", $lines),
            location: $activity->location,
            url: $url,
            lastModified: $activity->updated_at ?? $start,
        );
    }

    /** @param array<string, string> $replace */
    private static function say(string $key, array $replace): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    /** A stable UID per activity: the same event in every feed, file, invitation and calendar. */
    public static function uid(int $activityId): string
    {
        return "crm-activity-{$activityId}@".self::host();
    }

    public static function host(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'nonox');
    }
}
