<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Modules\StaffLedger\Actions\AppendEntry;
use App\Modules\StaffLedger\Actions\PersonCredentials;
use App\Modules\StaffLedger\Actions\PruneStaffLedger;
use App\Modules\StaffLedger\Domain\ChainVerifier;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Domain\Retention;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\StaffLedger;

/*
 * Retention of the staff ledger: two years after the end of the calendar
 * year in which the fiscal year (beskattningsår) containing the entry ended,
 * 9 kap. 5 § tredje stycket skatteförfarandeförordningen.
 *
 * The entries are dated years back: the append-only trigger lets a prune
 * delete only what is past retention by the database's own clock, so the
 * dates must be in the real past for the prune to go through.
 */

/**
 * A company with the given fiscal years and first month, the staff ledger on,
 * a location and a person.
 *
 * @param  list<array{0: string, 1: string}>  $years
 * @return array{0: User, 1: Company, 2: Location, 3: Person}
 */
function retentionCompany(array $years, int $startMonth = 1): array
{
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['fiscal_year_start_month' => $startMonth])->save();
    CurrentCompany::set($company);

    foreach ($years as [$start, $end]) {
        FiscalYear::query()->create(['company_id' => $company->id, 'start_date' => $start, 'end_date' => $end]);
    }

    StaffLedger::enable($company, $owner);
    $location = StaffLedger::location($owner, $company);
    $person = StaffLedger::person($owner, $company);

    return [$owner, $company, $location, $person];
}

function retentionEntry(Location $location, Person $person, string $at): Entry
{
    test()->travelTo(CarbonImmutable::parse($at));

    $entry = app(AppendEntry::class)($location, $person, ['type' => EntryType::CheckIn, 'method' => CheckInMethod::KioskPin, 'occurred_at' => CarbonImmutable::parse($at)]);

    test()->travelBack();

    return $entry;
}

function retainedEntries(Company $company): int
{
    return CompanyScope::bypass(fn () => Entry::query()->where('company_id', $company->id)->count());
}

it('keeps a calendar-year company\'s entries through the second calendar year after the year ends', function () {
    [, $company, $location, $person] = retentionCompany([['2019-01-01', '2019-12-31'], ['2020-01-01', '2020-12-31']]);
    retentionEntry($location, $person, '2019-06-15 08:00');

    expect(app(PruneStaffLedger::class)(CarbonImmutable::parse('2021-12-31 23:00'))['entries'])->toBe(0)
        ->and(retainedEntries($company))->toBe(1)
        ->and(app(PruneStaffLedger::class)(CarbonImmutable::parse('2022-01-01 01:00'))['entries'])->toBe(1)
        ->and(retainedEntries($company))->toBe(0);
});

it('counts from the end of a broken fiscal year, not from the entry\'s calendar year', function () {
    [, $company, $location, $person] = retentionCompany([['2019-05-01', '2020-04-30'], ['2020-05-01', '2021-04-30']], 5);
    retentionEntry($location, $person, '2019-06-15 08:00');
    $later = retentionEntry($location, $person, '2020-05-02 08:00');

    // The fiscal year 2019-05-01 – 2020-04-30 ends in 2020: kept through 2022-12-31.
    expect(app(PruneStaffLedger::class)(CarbonImmutable::parse('2022-01-01 01:00'))['entries'])->toBe(0)
        ->and(app(PruneStaffLedger::class)(CarbonImmutable::parse('2022-12-31 23:00'))['entries'])->toBe(0)
        ->and(retainedEntries($company))->toBe(2)
        ->and(app(PruneStaffLedger::class)(CarbonImmutable::parse('2023-01-01 01:00'))['entries'])->toBe(1);

    // The next fiscal year ends in 2021, so its entry stays until 2024.
    CurrentCompany::set($company);
    expect(Entry::query()->pluck('id')->all())->toBe([$later->id])
        ->and(app(ChainVerifier::class)->verify($company)['ok'])->toBeTrue()
        ->and(AuditLog::forCompany($company)->where('action', 'staff_ledger.pruned')->sole()->payload['last_removed_kept_until'])->toBe('2022-12-31');
});

it('counts an extended first fiscal year from its own end', function () {
    [, $company, $location, $person] = retentionCompany([['2019-09-01', '2020-12-31']]);
    retentionEntry($location, $person, '2019-10-01 08:00');

    expect(app(PruneStaffLedger::class)(CarbonImmutable::parse('2022-06-01'))['entries'])->toBe(0)
        ->and(app(PruneStaffLedger::class)(CarbonImmutable::parse('2022-12-31 23:00'))['entries'])->toBe(0)
        ->and(app(PruneStaffLedger::class)(CarbonImmutable::parse('2023-01-01 01:00'))['entries'])->toBe(1)
        ->and(retainedEntries($company))->toBe(0);
});

it('keeps everything when the company has no fiscal year', function () {
    [, $company, $location, $person] = retentionCompany([]);
    retentionEntry($location, $person, '2014-03-01 08:00');

    expect(app(PruneStaffLedger::class)(CarbonImmutable::parse('2034-01-01'))['entries'])->toBe(0)
        ->and(retainedEntries($company))->toBe(1);
});

it('derives a missing fiscal year from the first month, and keeps the entry when that is in doubt', function () {
    $years = collect([
        new FiscalYear(['start_date' => '2020-05-01', 'end_date' => '2021-04-30']),
    ]);
    $retention = new Retention($years, 5);

    // 2018-07-01 lies in the derived year 2018-05-01 – 2019-04-30, which ends in 2019.
    expect($retention->keptUntil(CarbonImmutable::parse('2018-07-01'))?->toDateString())->toBe('2021-12-31')
        ->and($retention->keptUntil(CarbonImmutable::parse('2020-06-01'))?->toDateString())->toBe('2023-12-31')
        ->and($retention->expired(CarbonImmutable::parse('2018-07-01'), CarbonImmutable::parse('2022-01-01')))->toBeTrue()
        ->and($retention->expired(CarbonImmutable::parse('2018-07-01'), CarbonImmutable::parse('2021-12-31')))->toBeFalse();

    // A first fiscal year that started in March: the derived year May – April overlaps it, so the date is in doubt.
    $doubt = new Retention(collect([new FiscalYear(['start_date' => '2019-03-01', 'end_date' => '2020-04-30'])]), 5);
    expect($doubt->keptUntil(CarbonImmutable::parse('2019-01-15')))->toBeNull()
        ->and($doubt->expired(CarbonImmutable::parse('2019-01-15'), CarbonImmutable::parse('2034-01-01')))->toBeFalse()
        ->and((new Retention(collect(), 1))->keptUntil(CarbonImmutable::parse('2014-01-01')))->toBeNull();
});

it('stops at the first kept entry, also when a later entry in the chain is older', function () {
    [, $company, $location, $person] = retentionCompany([['2019-05-01', '2020-04-30'], ['2020-05-01', '2021-04-30']], 5);
    retentionEntry($location, $person, '2019-06-15 08:00');
    $kept = retentionEntry($location, $person, '2020-06-15 08:00');
    retentionEntry($location, $person, '2019-07-01 08:00');

    expect(app(PruneStaffLedger::class)(CarbonImmutable::parse('2023-01-01 01:00'))['entries'])->toBe(1);

    CurrentCompany::set($company);
    expect(Entry::query()->min('id'))->toBe($kept->id)
        ->and(app(ChainVerifier::class)->verify($company)['ok'])->toBeTrue();
});

it('removes an archived person without entries when the archiving year is past retention', function () {
    [$owner, $company, , $person] = retentionCompany([['2019-05-01', '2020-04-30']], 5);
    $this->travelTo(CarbonImmutable::parse('2019-06-15 10:00'));
    app(PersonCredentials::class)->archive($owner, $company, $person, true);
    $this->travelBack();

    expect(app(PruneStaffLedger::class)(CarbonImmutable::parse('2022-06-01'))['people'])->toBe(0)
        ->and(app(PruneStaffLedger::class)(CarbonImmutable::parse('2023-01-01 01:00'))['people'])->toBe(1)
        ->and(CompanyScope::bypass(fn () => DB::table('staff_ledger_people')->where('id', $person->id)->exists()))->toBeFalse();
});

it('loads the fiscal years once, not once per location or entry', function () {
    foreach (range(1, 3) as $i) {
        [, , $location, $person] = retentionCompany([['2019-01-01', '2019-12-31']]);
        retentionEntry($location, $person, '2019-03-01 08:00');
        retentionEntry($location, $person, '2019-03-01 16:00');
    }

    DB::enableQueryLog();
    app(PruneStaffLedger::class)(CarbonImmutable::parse('2020-06-01'));
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'from "fiscal_years"'))->count())->toBe(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from "teams"'))->count())->toBeLessThanOrEqual(1);
});

it('refuses a delete inside the retention period even with the prune flag set', function () {
    // Any role can set the flag; the database's own clock and the fiscal years decide.
    [, $company, $location, $person] = retentionCompany([[now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()]]);
    $entry = retentionEntry($location, $person, now()->startOfYear()->addDay()->setTime(8, 0)->toDateTimeString());

    $refused = null;

    try {
        DB::transaction(function () use ($entry): void {
            DB::statement("select set_config('nonox.staff_ledger_prune', 'on', true)");
            DB::delete('delete from staff_ledger_entries where id = ?', [$entry->id]);
        });
    } catch (QueryException $exception) {
        $refused = $exception->getMessage();
    }

    expect($refused)->toContain('staff_ledger_entries is append-only')
        ->and(retainedEntries($company))->toBe(1);
});

it('lets the prune delete an entry past retention by the database clock', function () {
    [, $company, $location, $person] = retentionCompany([['2019-01-01', '2019-12-31']]);
    retentionEntry($location, $person, '2019-03-01 08:00');

    expect(app(PruneStaffLedger::class)()['entries'])->toBe(1)
        ->and(retainedEntries($company))->toBe(0);
});
