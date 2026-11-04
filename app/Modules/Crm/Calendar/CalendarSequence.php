<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Raises the calendar sequence of activities whose calendar copy changes
 * without the activity itself being saved (ADR-049): an erased person, an
 * anonymised customer, a person marked Kontakta inte, a renamed account or
 * person. Subscribed calendars read the new version on their next fetch,
 * and a connected calendar is written again on the next sync round
 * (synced_sequence is then behind). Mass updates fire no model events, so
 * they add `self::column()` to their own update.
 */
final class CalendarSequence
{
    /** @return array{calendar_sequence: Expression} */
    public static function column(): array
    {
        return ['calendar_sequence' => DB::raw('calendar_sequence + 1')];
    }

    public static function forAccount(int $accountId): void
    {
        // An account belongs to one company, so its activities are that company's alone; the scope may not be set in an observer.
        CompanyScope::bypass(fn () => Activity::query()->where('account_id', $accountId)->whereNull('done_at')->toBase()->update(self::column()));
    }

    public static function forPerson(int $personId): void
    {
        // As above: a person belongs to one company. A private person's account shows its people's flags, so its other activities change too.
        CompanyScope::bypass(function () use ($personId): void {
            $accounts = DB::table('crm_account_person')->where('person_id', $personId)->pluck('account_id')->all();

            Activity::query()
                ->whereNull('done_at')
                ->where(fn ($query) => $query->where('person_id', $personId)->orWhereIn('account_id', $accounts))
                ->toBase()
                ->update(self::column());
        });
    }

    /** Model observer: a renamed account. */
    public function accountUpdated(Account $account): void
    {
        if ($account->wasChanged(['name', 'kind'])) {
            self::forAccount($account->id);
        }
    }

    /** Model observer: a renamed person, or one marked or unmarked Kontakta inte. */
    public function personUpdated(Person $person): void
    {
        if ($person->wasChanged(['name', 'do_not_contact_at', 'do_not_contact_reason'])) {
            self::forPerson($person->id);
        }
    }
}
