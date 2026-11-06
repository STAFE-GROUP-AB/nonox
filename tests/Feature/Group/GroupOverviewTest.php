<?php

declare(strict_types=1);

use App\Actions\Group\ManageCompanyGroup;
use App\Domain\Accounting\AccountType;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupOverview;
use App\Domain\Group\MarkKind;
use App\Livewire\Group\GroupOverviewPage;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMark;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Koncernöversikt (ADR-060): a holding company H, two subsidiaries S1 and S2.
 * All amounts are in öre; the numbers below are worked out by hand.
 *
 *   H : 1930 12 000 kr, 1660 10 000 kr, 1310 30 000 kr | 2081 50 000 kr, result 2 000 kr (sales 3001)
 *   S1: 1930 37 500 kr | 2081 30 000 kr, 2460 9 000 kr, result -1 500 kr (purchases 4010)
 *   S2: 1930 1 000 kr | result 1 000 kr (sales 3001)
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    $this->me = User::factory()->create(['name' => 'Ägaren']);

    foreach (['h' => 'Holding AB', 's1' => 'Dotter Ett AB', 's2' => 'Dotter Två AB'] as $key => $name) {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['name' => $name])->save();
        $company->users()->attach($this->me, ['role' => $key === 'owner' ? 'owner' : 'accountant']);
        $this->{$key} = $company;
        $this->{"{$key}Owner"} = $owner;
    }

    grpAccount($this->h, 1660, 'Kortfristiga fordringar hos koncernföretag');
    grpAccount($this->s1, 2460, 'Skulder till koncernföretag');

    grpPost($this->h, '2026-02-01', [1930 => 5_000_000, 2081 => -5_000_000]);
    grpPost($this->h, '2026-03-01', [1660 => 1_000_000, 1930 => -1_000_000]);
    grpPost($this->h, '2026-03-02', [1310 => 3_000_000, 1930 => -3_000_000]);
    grpPost($this->h, '2026-04-01', [1930 => 200_000, 3001 => -200_000]);
    grpPost($this->s1, '2026-02-01', [1930 => 3_000_000, 2081 => -3_000_000]);
    grpPost($this->s1, '2026-03-01', [1930 => 900_000, 2460 => -900_000]);
    grpPost($this->s1, '2026-04-02', [4010 => 150_000, 1930 => -150_000]);
    grpPost($this->s2, '2026-05-01', [1930 => 100_000, 3001 => -100_000]);

    $manage = app(ManageCompanyGroup::class);
    $this->group = $manage->create($this->me, 'Min koncern');

    foreach ([$this->h, $this->s1, $this->s2] as $company) {
        $manage->addCompany($this->me, $this->group->id, $company->id, $company->id === $this->s1->id ? '62,5' : null);
    }
});

function grpAccount(Company $company, int $number, string $name): void
{
    CurrentCompany::run($company, fn () => Account::query()->updateOrCreate(['number' => $number], ['name' => $name, 'type' => AccountType::forNumber($number), 'is_active' => true]));
}

/** @param  array<int, int>  $rows account => signed öre (debit positive) */
function grpPost(Company $company, string $date, array $rows): void
{
    CurrentCompany::run($company, fn () => Bookkeeping::postRows($company->owner, $company, $date, $rows));
}

function grpMarkAll(): void
{
    $manage = app(ManageCompanyGroup::class);
    $me = test()->me;
    $manage->mark($me, test()->group->id, test()->h->id, 1660, 'RECEIVABLE');
    $manage->mark($me, test()->group->id, test()->h->id, 1310, 'SHARES');
    $manage->mark($me, test()->group->id, test()->h->id, 3001, 'SALES');
    $manage->mark($me, test()->group->id, test()->s1->id, 2460, 'PAYABLE');
    $manage->mark($me, test()->group->id, test()->s1->id, 2081, 'EQUITY');
    $manage->mark($me, test()->group->id, test()->s1->id, 4010, 'PURCHASES');
}

/** @return list<list<?string>> */
function grpCsv(string $content): array
{
    return array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\n", trim(ltrim($content, "\xEF\xBB\xBF"))));
}

function grpReport(?string $year = null): array
{
    $access = GroupAccess::for(test()->me->fresh());

    return app(GroupOverview::class)->build($access, $access->group(test()->group->id), $year);
}

it('shows the income statement and balance sheet side by side with a sum column', function () {
    $report = grpReport();
    $ids = array_column($report['companies'], 'id');

    // Companies by name.
    expect(array_column($report['companies'], 'name'))->toBe(['Dotter Ett AB', 'Dotter Två AB', 'Holding AB'])
        ->and(collect($report['companies'])->firstWhere('id', $this->s1->id)['ownership_bp'])->toBe(6250);

    $revenue = $report['income']['groups'][0];
    $sales = collect($revenue['rows'])->firstWhere('number', 3001);

    expect($sales['amounts'])->toBe([$this->s1->id => 0, $this->s2->id => 100_000, $this->h->id => 200_000])
        ->and($sales['sum'])->toBe(300_000)
        ->and($report['income']['result']['amounts'])->toBe([$this->s1->id => -150_000, $this->s2->id => 100_000, $this->h->id => 200_000])
        ->and($report['income']['result']['sum'])->toBe(150_000);

    $assets = $report['balance']['sides']['assets'];
    $equity = $report['balance']['sides']['equity'];

    expect($assets['totals'])->toBe([$this->s1->id => 3_750_000, $this->s2->id => 100_000, $this->h->id => 5_200_000])
        ->and($assets['sum'])->toBe(9_050_000)
        // The two sides meet in every company, because the unbooked result is on the equity side.
        ->and($equity['totals'])->toBe($assets['totals'])
        ->and($equity['unbooked']['sum'])->toBe(150_000);
});

it('suggests eliminations with the smaller side of each pair, and books nothing', function () {
    grpMarkAll();
    $vouchers = DB::table('vouchers')->count();

    $report = grpReport();
    $pairs = collect($report['eliminations'])->keyBy('key');

    // Receivable 10 000 kr against payable 9 000 kr.
    expect($pairs['balances'])->toMatchArray(['left' => 1_000_000, 'right' => 900_000, 'eliminate' => 900_000, 'difference' => 100_000]);
    // Sales 2 000 kr against purchases 1 500 kr.
    expect($pairs['trade'])->toMatchArray(['left' => 200_000, 'right' => 150_000, 'eliminate' => 150_000, 'difference' => 50_000]);
    // Shares 30 000 kr against the subsidiary's equity 30 000 kr.
    expect($pairs['shares'])->toMatchArray(['left' => 3_000_000, 'right' => 3_000_000, 'eliminate' => 3_000_000, 'difference' => 0]);

    $adjusted = collect($report['adjusted'])->keyBy('label');

    expect($adjusted['Tillgångar'])->toMatchArray(['before' => 9_050_000, 'after' => 5_150_000])
        ->and($adjusted['Eget kapital och skulder'])->toMatchArray(['before' => 9_050_000, 'after' => 5_150_000])
        ->and($adjusted['Rörelsens intäkter'])->toMatchArray(['before' => 300_000, 'after' => 150_000]);

    expect(DB::table('vouchers')->count())->toBe($vouchers);
});

it('suggests marks from the account names and lets the user change them', function () {
    $count = app(ManageCompanyGroup::class)->suggest($this->me, $this->group->id);

    // The seeded 1310 in all three companies, 1660 in the holding company and 2460 in the first subsidiary.
    expect($count)->toBe(5);

    $kinds = CompanyGroupMark::query()->orderBy('account_number')->distinct()->pluck('kind', 'account_number')->all();

    expect($kinds)->toBe([1310 => 'SHARES', 1660 => 'RECEIVABLE', 2460 => 'PAYABLE'])
        ->and(app(ManageCompanyGroup::class)->suggest($this->me, $this->group->id))->toBe(0)
        ->and(MarkKind::suggest(8310, 'Ränteintäkter från koncernföretag'))->toBeNull()
        ->and(MarkKind::suggest(8210, 'Utdelning på andelar i koncernföretag'))->toBeNull();
});

it('leaves out a company whose fiscal year differs, with a note, and keeps it out of the sums', function () {
    DB::table('fiscal_years')->where('company_id', $this->s2->id)->update(['start_date' => '2026-05-01', 'end_date' => '2027-04-30']);

    $report = grpReport();

    expect(array_column($report['companies'], 'id'))->toEqualCanonicalizing([$this->h->id, $this->s1->id])
        ->and($report['excluded'])->toHaveCount(1)
        ->and($report['excluded'][0]['name'])->toBe('Dotter Två AB')
        ->and($report['income']['result']['sum'])->toBe(50_000)
        ->and(array_column($report['years'], 'companies'))->toBe([1, 2]);

    // Choosing the other year shows the company that has it.
    $other = grpReport('2026-05-01_2027-04-30');

    expect(array_column($other['companies'], 'id'))->toBe([$this->s2->id]);

    Livewire::actingAs($this->me)->test(GroupOverviewPage::class)->assertSee('Dotter Två AB: har inte räkenskapsåret');
});

it('runs a fixed number of queries whatever the number of accounts', function () {
    grpMarkAll();
    grpReport();

    DB::enableQueryLog();
    grpReport();
    $few = count(DB::getQueryLog());

    DB::flushQueryLog();

    foreach ([$this->h, $this->s1, $this->s2] as $company) {
        $rows = [];

        foreach (range(5100, 5139) as $number) {
            grpAccount($company, $number, "Kostnad {$number}");
            $rows[$number] = 1000;
        }

        $rows[1930] = -array_sum($rows);
        grpPost($company, '2026-06-01', $rows);
    }

    DB::flushQueryLog();
    $report = grpReport();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few)
        ->and(count(collect($report['income']['groups'])->firstWhere('key', 'external')['rows']))->toBe(40);
});

it('shows the page with the internal-reporting notice, in print too, and a CSV of the same figures', function () {
    grpMarkAll();
    $this->actingAs($this->me);

    $this->get(route('group.overview'))->assertOk()
        ->assertSee('Intern översikt, inte en koncernredovisning')
        ->assertSee('Min koncern')
        ->assertSee('Holding AB')
        ->assertSee('Ägarandel 62,5 %')
        ->assertSee('Hämta som CSV');

    Livewire::test(GroupOverviewPage::class)->call('show', 'balans')->assertSee('Resultat som ännu inte är bokfört')
        ->call('show', 'elimineringar')->assertSee('Koncerninterna fordringar och skulder')->assertSee('Förslag är inte bokningar');

    $csv = $this->get(route('group.csv', ['group' => $this->group->id, 'rapport' => 'resultat']))->assertOk();

    expect($csv->headers->get('Content-Type'))->toContain('text/csv');

    $lines = grpCsv($csv->streamedContent());

    expect($lines[0])->toBe(['Intern koncernöversikt, inte en koncernredovisning', ''])
        ->and($lines[1])->toBe(['', 'Dotter Ett AB', 'Dotter Två AB', 'Holding AB', 'Summa'])
        ->and($lines)->toContain(['3001 Försäljning inom Sverige, 25 % moms', '0,00', '1000,00', '2000,00', '3000,00'])
        ->and($lines)->toContain(['Årets resultat', '-1500,00', '1000,00', '2000,00', '1500,00']);

    $elimination = grpCsv($this->get(route('group.csv', ['group' => $this->group->id, 'rapport' => 'elimineringar']))->streamedContent());

    expect($elimination)->toContain(['Koncerninterna fordringar och skulder', 'Fordran på koncernföretag', '10000,00', 'Skuld till koncernföretag', '9000,00', 'Kan elimineras', '9000,00', 'Skillnad', '1000,00']);
});

it('lets an auditor see the group, and drops a company where the user may not see reports', function () {
    $this->h->users()->updateExistingPivot($this->me->id, ['role' => 'auditor']);

    expect(array_column(grpReport()['companies'], 'name'))->toContain('Holding AB');

    // An approver may not see reports: the company disappears and is only counted.
    $this->s2->users()->updateExistingPivot($this->me->id, ['role' => 'approver']);
    $report = grpReport();

    expect(array_column($report['companies'], 'name'))->not->toContain('Dotter Två AB')
        ->and($report['hidden'])->toBe(1)
        ->and($report['income']['result']['sum'])->toBe(50_000);

    $this->actingAs($this->me->fresh())->get(route('group.overview'))->assertOk()
        ->assertDontSee('Dotter Två AB')
        ->assertSee('Ett företag i koncernen visas inte');
});

it('answers 403 when the user may see reports of no company', function () {
    $stranger = User::factory()->create();
    $this->s1->users()->attach($stranger, ['role' => 'approver']);

    $this->actingAs($stranger)->get(route('group.overview'))->assertForbidden();
});

it('manages groups, companies and marks with checks in Swedish', function () {
    $component = Livewire::actingAs($this->me)->test(GroupOverviewPage::class)
        ->set('newName', 'Min koncern')->call('create')->assertDispatched('toast', type: 'error', message: 'Du har redan en koncern med det namnet.')
        ->set('newName', 'Andra koncernen')->call('create')->assertDispatched('toast', type: 'success', message: 'Koncernen är skapad. Lägg till företagen i den.');

    $second = CompanyGroup::query()->where('name', 'Andra koncernen')->sole();

    $component->assertSet('group', (string) $second->id)->assertSet('tab', 'installningar')
        ->set('addCompany', (string) $this->h->id)->set('addShare', '150')->call('add')
        ->assertDispatched('toast', type: 'error', message: 'Ägarandelen ska vara mellan 0 och 100 procent.')
        ->set('addShare', '100')->call('add')->assertDispatched('toast', type: 'success', message: 'Företaget ingår nu i koncernen.');

    expect($second->members()->sole()->ownership_bp)->toBe(10000);

    $component->set('markCompany', (string) $this->h->id)->set('markAccount', '9999')->set('markKind', 'RECEIVABLE')->call('mark')
        ->assertDispatched('toast', type: 'error', message: 'Kontot finns inte i företagets kontoplan.')
        ->set('markAccount', '1660')->call('mark')->assertDispatched('toast', type: 'success', message: 'Kontot är markerat som koncerninternt.')
        ->assertSee('Fordran på koncernföretag');

    $mark = CompanyGroupMark::query()->where('company_group_id', $second->id)->sole();
    $component->call('unmark', $mark->id)->call('remove', $this->h->id)->call('deleteGroup');

    expect(CompanyGroup::query()->whereKey($second->id)->exists())->toBeFalse()
        ->and(CompanyGroup::query()->whereKey($this->group->id)->exists())->toBeTrue();
});

it('refuses a company in a group where the user may not see reports', function () {
    $outsider = Bookkeeping::company()[1];

    expect(fn () => app(ManageCompanyGroup::class)->addCompany($this->me, $this->group->id, $outsider->id))->toThrow(ValidationException::class);

    $this->s2->users()->updateExistingPivot($this->me->id, ['role' => 'approver']);

    expect(fn () => app(ManageCompanyGroup::class)->addCompany($this->me->fresh(), $this->group->id, $this->s2->id))->toThrow(ValidationException::class);
});

it('notes an account that the companies name differently, in the page and the CSV', function () {
    CurrentCompany::run($this->s2, fn () => Account::query()->where('number', 3001)->update(['name' => 'Försäljning, egen benämning']));

    $row = collect(grpReport()['income']['groups'][0]['rows'])->firstWhere('number', 3001);

    expect($row['name_differs'])->toBeTrue()
        ->and(GroupOverview::rowLabel($row))->toBe('3001 Försäljning, egen benämning (namnet skiljer sig mellan företagen)');

    Livewire::actingAs($this->me)->test(GroupOverviewPage::class)->assertSee('namnet skiljer sig mellan företagen');

    $lines = grpCsv($this->actingAs($this->me)->get(route('group.csv', ['group' => $this->group->id, 'rapport' => 'resultat']))->streamedContent());

    expect(collect($lines)->pluck(0))->toContain('3001 Försäljning, egen benämning (namnet skiljer sig mellan företagen)');

});

it('resets to another group with a toast when the selected group was deleted elsewhere', function () {
    $other = app(ManageCompanyGroup::class)->create($this->me, 'Andra');

    $component = Livewire::actingAs($this->me)->test(GroupOverviewPage::class)->call('select', (string) $this->group->id)->assertSet('group', (string) $this->group->id);
    CompanyGroupMark::query()->delete();
    DB::table('company_groups')->where('id', $this->group->id)->delete();

    $component->call('show', 'balans')->assertOk()
        ->assertSet('group', (string) $other->id)
        ->assertDispatched('toast', type: 'error', message: 'Koncernen finns inte längre. Den kan ha tagits bort i ett annat fönster.');
});

it('adds a label header to the adjusted totals of the elimination CSV', function () {
    grpMarkAll();

    $lines = grpCsv($this->actingAs($this->me)->get(route('group.csv', ['group' => $this->group->id, 'rapport' => 'elimineringar']))->streamedContent());

    expect($lines)->toContain(['Summa', 'Före', 'Efter förslagen']);
});
