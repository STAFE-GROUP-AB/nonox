<?php

declare(strict_types=1);

/*
 * VAT periods are calendar months, calendar quarters or the beskattningsår,
 * never cut at a broken fiscal year (SFL 2011:1244 26 kap. 10, 11 och 26 §§,
 * read 2026-11-02 at riksdagen.se; Skatteverket "När ska jag deklarera moms?":
 * "Kvartal = kalenderkvartal"). Enligt granskning 2026-11-02, juristgranskning
 * återstår (AUD-MOMS-6, #59). Every expected value is computed by hand.
 */

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Vat\SettleVat;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\VatPeriod;
use App\Domain\Vat\EskdFile;
use App\Domain\Vat\VatDeadlines;
use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Livewire\Vat\VatReturns;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));
    $this->owner = User::factory()->withCompany()->create();
    $this->company = $this->owner->currentCompany();
    $this->company->forceFill(['legal_form' => 'AB', 'fiscal_year_start_month' => 5, 'vat_period' => VatPeriod::Quarterly, 'org_number' => '556334-3689'])->save();
    app(InitialiseBookkeeping::class)($this->company, $this->owner, CarbonImmutable::parse('2026-06-15'));
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->second = FiscalYear::query()->whereDate('start_date', '2026-05-01')->sole();
    $this->first = FiscalYear::create(['start_date' => '2025-05-01', 'end_date' => '2026-04-30']);
});

/** @param  list<array<string, mixed>>  $rows */
function calendarBook(User $user, Company $company, string $date, array $rows): void
{
    app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, ['voucher_date' => $date, 'description' => 'Moms över årsskiftet', 'rows' => $rows]));
}

/** @return list<string> */
function calendarPeriods(FiscalYear $year, VatPeriod $period): array
{
    return array_map(fn (array $p): string => $p['start']->toDateString().'/'.$p['end']->toDateString(), VatPeriods::of($year, $period));
}

function quarterAcrossTheYearEnd(User $user, Company $company): void
{
    // Q1 2026, fiscal year 1: 400 kr + 100 kr VAT. Not part of Q2.
    calendarBook($user, $company, '2026-03-15', [['account' => 1930, 'debit_ore' => 50_000], ['account' => 3001, 'credit_ore' => 40_000], ['account' => 2611, 'credit_ore' => 10_000]]);
    // Q2 2026: April in fiscal year 1, May in fiscal year 2.
    calendarBook($user, $company, '2026-04-10', [['account' => 1930, 'debit_ore' => 125_000], ['account' => 3001, 'credit_ore' => 100_000], ['account' => 2611, 'credit_ore' => 25_000]]);
    calendarBook($user, $company, '2026-05-20', [['account' => 1930, 'debit_ore' => 250_000], ['account' => 3001, 'credit_ore' => 200_000], ['account' => 2611, 'credit_ore' => 50_000]]);
    calendarBook($user, $company, '2026-06-05', [['account' => 4010, 'debit_ore' => 40_000], ['account' => 2641, 'debit_ore' => 10_000], ['account' => 1930, 'credit_ore' => 50_000]]);
}

it('lists whole calendar quarters for a broken fiscal year, the straddling ones in both years', function () {
    expect(calendarPeriods($this->first, VatPeriod::Quarterly))->toBe([
        '2025-04-01/2025-06-30', '2025-07-01/2025-09-30', '2025-10-01/2025-12-31', '2026-01-01/2026-03-31', '2026-04-01/2026-06-30',
    ])
        ->and(calendarPeriods($this->second, VatPeriod::Quarterly))->toBe([
            '2026-04-01/2026-06-30', '2026-07-01/2026-09-30', '2026-10-01/2026-12-31', '2027-01-01/2027-03-31', '2027-04-01/2027-06-30',
        ])
        // Months never straddle: a fiscal year is whole months (BFL 3 kap.).
        ->and(calendarPeriods($this->second, VatPeriod::Monthly))->toHaveCount(12)
        // The beskattningsår of a company is its fiscal year (SFL 26 kap. 11 § andra meningen).
        ->and(calendarPeriods($this->second, VatPeriod::Yearly))->toBe(['2026-05-01/2027-04-30']);
});

it('declares a quarter that straddles the year end as one return, with the quarter deadline and file period', function () {
    quarterAcrossTheYearEnd($this->owner, $this->company);

    $fromFirst = VatReturn::calculate($this->first, CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-06-30'));
    $fromSecond = VatReturn::calculate($this->second, CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-06-30'));

    // Box 05: 1 000 + 2 000 = 3 000; box 10: 250 + 500 = 750; box 48: 100; box 49: 650.
    expect(array_filter($fromFirst->boxes()))->toBe([5 => 3_000, 10 => 750, 48 => 100, 49 => 650])
        ->and($fromSecond->boxes())->toBe($fromFirst->boxes())
        ->and(EskdFile::render($this->company, $fromFirst))->toContain('<Period>202606</Period>')
        // SFL 26 kap. 26 §: the 12th of the second month after the quarter, the 17th in August.
        ->and(VatDeadlines::for(VatPeriod::Quarterly, CarbonImmutable::parse('2026-06-30'), false)?->toDateString())->toBe('2026-08-17');
});

it('settles the straddling quarter once, on its last day, in the fiscal year that day belongs to', function () {
    quarterAcrossTheYearEnd($this->owner, $this->company);

    $report = app(SettleVat::class)($this->owner, $this->first, CarbonImmutable::parse('2026-04-01'));
    $voucher = $report->voucher()->with('rows.account', 'series')->sole();

    expect($voucher->series->code)->toBe('M')
        ->and($voucher->voucher_date->toDateString())->toBe('2026-06-30')
        ->and($voucher->fiscal_year_id)->toBe($this->second->id)
        ->and($voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())
        ->toBe([2611 => 75_000, 2641 => -10_000, 2650 => -65_000])
        ->and($report->period_end->toDateString())->toBe('2026-06-30')
        ->and(fn () => app(SettleVat::class)($this->owner, $this->second, CarbonImmutable::parse('2026-04-01')))->toThrow(ValidationException::class, 'redan bokförd');
});

it('leaves the straddling quarter out of the first year\'s closing checklist and shows it in both years', function () {
    quarterAcrossTheYearEnd($this->owner, $this->company);

    $vat = collect(ClosingChecklist::for($this->company, $this->first))->keyBy('key')->get('vat');

    expect($vat['detail'])->toContain('2026-01-01 – 2026-03-31')
        ->and($vat['detail'])->not->toContain('2026-04-01 – 2026-06-30');

    Livewire::test(VatReturns::class)->set('ar', (string) $this->first->id)->set('period', '2026-04-01')
        ->assertSee('Momsdeklaration 2026-04-01 – 2026-06-30');
});
