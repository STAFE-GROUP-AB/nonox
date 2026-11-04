<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
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

    public static function forDeal(int $dealId): void
    {
        // A deal belongs to one company; see forAccount.
        CompanyScope::bypass(fn () => Activity::query()->where('deal_id', $dealId)->whereNull('done_at')->toBase()->update(self::column()));
    }

    /** A person linked to or unlinked from a private person's account: a flag there changes what the account's events show. */
    public static function forLink(int $accountId, int $personId): void
    {
        $affects = CompanyScope::bypass(fn (): bool => Account::query()->whereKey($accountId)->where('kind', AccountKind::Person->value)->exists()
            && Person::query()->whereKey($personId)->whereNotNull('do_not_contact_at')->exists());

        if ($affects) {
            self::forAccount($accountId);
        }
    }

    /** Model observer: a renamed deal (its title is in calendars with details). */
    public function dealUpdated(Deal $deal): void
    {
        if ($deal->wasChanged('title')) {
            self::forDeal($deal->id);
        }
    }

    /** Model observer: a person linked to an account. */
    public function linkCreated(AccountPerson $link): void
    {
        self::forLink($link->account_id, $link->person_id);
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
