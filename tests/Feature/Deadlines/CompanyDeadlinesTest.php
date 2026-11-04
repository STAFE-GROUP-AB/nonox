<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Deadlines\FiscalCalendar;
use App\Domain\Deadlines\Workdays;
use App\Domain\Invoicing\ForeignSale;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Bookkeeping;

/*
 * Mina datum's engine (CompanyDeadlines, ADR-051 stage 2): which dates a
 * company gets, from which rules, and why.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->deadlines = app(CompanyDeadlines::class);
    $this->set = function (array $attributes): void {
        $this->company->forceFill($attributes)->save();
        $this->company->refresh();
    };
    $this->dates = fn (string $from, int $months = 15, ?DeadlineType $type = null): array => array_values(array_map(
        fn (Deadline $deadline): string => "{$deadline->key} {$deadline->date->toDateString()}",
        array_filter($this->deadlines->upcoming($this->company, CarbonImmutable::parse($from), $months), fn (Deadline $deadline): bool => $type === null || $deadline->type === $type),
    ));
});

it('dates quarterly VAT on the 12th of the second month, the 17th in August, moved past weekends', function () {
    ($this->set)(['legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly, 'turnover_over_40_msek' => false]);

    expect(($this->dates)('2026-11-04', 13, DeadlineType::Vat))->toBe([
        'vat:2026-09-30 2026-11-12',
        'vat:2026-12-31 2027-02-12',
        'vat:2027-03-31 2027-05-12',
        'vat:2027-06-30 2027-08-17',
        'vat:2027-09-30 2027-11-12',
    ]);

    $deadline = collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2026-11-04'), 1))->firstWhere('type', DeadlineType::Vat);
    expect($deadline->title)->toBe('Momsdeklaration för kvartal 3 2026')
        ->and($deadline->appliesBecause)->toBe('Ni redovisar moms varje kvartal.')
        ->and($deadline->route)->toBe('vat.index')
        ->and($deadline->legalVerify)->toBeFalse()
        ->and($deadline->isRecommendation)->toBeFalse();
});

it('dates monthly VAT on the 12th, the 17th in January and August, the next weekday after a weekend', function () {
    ($this->set)(['legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Monthly, 'turnover_over_40_msek' => false]);

    // October 2026 → 12 December 2026 is a Saturday → Monday 14 December. November → 17 January 2027 is a Sunday → 18 January.
    expect(array_slice(($this->dates)('2026-11-04', 3, DeadlineType::Vat), 0, 3))->toBe([
        'vat:2026-09-30 2026-11-12',
        'vat:2026-10-31 2026-12-14',
        'vat:2026-11-30 2027-01-18',
    ]);
});

it('dates monthly VAT above 40 million kronor on the 26th of the next month, the 27th in December', function () {
    ($this->set)(['legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Monthly, 'turnover_over_40_msek' => true]);

    expect(array_slice(($this->dates)('2026-11-04', 2, DeadlineType::Vat), 0, 2))->toBe([
        'vat:2026-10-31 2026-11-26',
        'vat:2026-11-30 2026-12-28',
    ]);

    expect(collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2026-11-04'), 1))->firstWhere('type', DeadlineType::Vat)->appliesBecause)
        ->toBe('Ni redovisar moms varje månad och omsätter mer än 40 miljoner kronor om året.');
});

it('dates yearly VAT by legal form, a broken year by the next named year end (SFL 3 kap. 5 §)', function () {
    ($this->set)(['legal_form' => LegalForm::Ef, 'vat_registered' => true, 'vat_period' => VatPeriod::Yearly]);
    expect(($this->dates)('2026-11-04', 15, DeadlineType::Vat))->toBe(['vat:2026-12-31 2027-05-12']);

    ($this->set)(['legal_form' => LegalForm::Ab]);
    $deadline = collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2026-11-04')))->firstWhere('type', DeadlineType::Vat);
    expect("{$deadline->key} {$deadline->date->toDateString()}")->toBe('vat:2026-12-31 2027-08-17')
        ->and($deadline->legalVerify)->toBeFalse()
        ->and($deadline->title)->toBe('Momsdeklaration för 2026');

    FiscalYear::query()->delete();
    FiscalYear::create(['start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
    ($this->set)(['fiscal_year_start_month' => 4]);
    // A year ending 31 March counts as ending 30 April: 12 December 2027 is a Sunday → 13 December.
    $deadline = collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2026-11-04')))->firstWhere('type', DeadlineType::Vat);
    expect($deadline->date->toDateString())->toBe('2027-12-13')->and($deadline->legalVerify)->toBeFalse();
});

it('dates a sole trader\'s yearly VAT with EU trade on the 26th of the second month (REGLER.md §11)', function () {
    ($this->set)(['legal_form' => LegalForm::Ef, 'vat_registered' => true, 'vat_period' => VatPeriod::Yearly]);
    $fiscalYear = FiscalYear::query()->sole();
    $account = Account::query()->where('number', 3108)->first() ?? Account::create(['number' => 3108, 'name' => 'Försäljning varor till annat EU-land', 'type' => 'REVENUE']);
    $voucher = Bookkeeping::post($this->owner, $this->company, 100000, 1930, 3108, '2026-03-01');

    expect(($this->dates)('2026-11-04', 15, DeadlineType::Vat))->toBe(['vat:2026-12-31 2027-02-26']);
});

it('follows a broken fiscal year, including the years NONOX has not created yet', function () {
    FiscalYear::query()->delete();
    FiscalYear::create(['start_date' => '2026-05-01', 'end_date' => '2027-04-30']);
    ($this->set)(['legal_form' => LegalForm::Ab, 'fiscal_year_start_month' => 5, 'vat_registered' => true, 'vat_period' => VatPeriod::Yearly]);

    // Fiscal year ending April 2027, filed electronically: 12 December 2027 is a Sunday → Monday 13 December.
    expect(($this->dates)('2026-11-04', 15, DeadlineType::Vat))->toBe(['vat:2027-04-30 2027-12-13']);

    $years = FiscalCalendar::years($this->company, CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2029-01-01'));
    expect(array_map(fn (FiscalYear $year): string => $year->start_date->toDateString().'–'.$year->end_date->toDateString(), $years))->toBe([
        '2026-05-01–2027-04-30', '2027-05-01–2028-04-30', '2028-05-01–2029-04-30',
    ])->and($years[1]->exists)->toBeFalse();
});

it('gives a company that is not registered for VAT no VAT dates', function () {
    ($this->set)(['vat_registered' => false, 'vat_period' => VatPeriod::None]);

    expect(($this->dates)('2026-11-04', 15, DeadlineType::Vat))->toBe([]);
});

it('dates the employer declaration from the law, also for years without a published list (REGLER.md §2, §11)', function () {
    ($this->set)(['employer_registered' => true, 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly, 'turnover_over_40_msek' => false]);

    expect(array_slice(($this->dates)('2026-11-04', 3, DeadlineType::Agi), 0, 3))->toBe([
        'agi:2026-10 2026-11-12',
        'agi:2026-11 2026-12-14',
        'agi:2026-12 2027-01-18',
    ]);
    expect(($this->dates)('2027-07-01', 2, DeadlineType::Agi))->toContain('agi:2027-07 2027-08-17');

    ($this->set)(['turnover_over_40_msek' => true]);
    expect(($this->dates)('2027-01-01', 1, DeadlineType::Agi))->toContain('agi-payment:2026-12 2027-01-18', 'agi:2026-12 2027-01-26');

    ($this->set)(['vat_registered' => false, 'vat_period' => VatPeriod::None]);
    expect(($this->dates)('2027-01-01', 1, DeadlineType::Agi))->toContain('agi:2026-12 2027-01-18')->not->toContain('agi-payment:2026-12 2027-01-18');

    ($this->set)(['employer_registered' => false]);
    expect(($this->dates)('2026-11-04', 3, DeadlineType::Agi))->toBe([]);
});

it('dates preliminary tax monthly from the fiscal year\'s second month, and a top-up as advice (REGLER.md §3, §11)', function () {
    ($this->set)(['legal_form' => LegalForm::Ab, 'f_skatt' => true]);

    expect(($this->dates)('2027-01-01', 1, DeadlineType::PreliminaryTax))->toContain('preliminary_tax:2027-01 2027-01-18');
    $payment = collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2027-01-01'), 1))->firstWhere('key', 'preliminary_tax:2027-01');
    expect($payment->legalVerify)->toBeTrue()->and($payment->isRecommendation)->toBeFalse();

    FiscalYear::query()->delete();
    FiscalYear::create(['start_date' => '2026-05-01', 'end_date' => '2027-04-30']);
    ($this->set)(['fiscal_year_start_month' => 5]);
    $series = array_values(array_filter(($this->dates)('2026-06-01', 12, DeadlineType::PreliminaryTax), fn (string $line): bool => str_starts_with($line, 'preliminary_tax:')));
    expect($series)->toHaveCount(12)
        ->and($series[0])->toBe('preliminary_tax:2026-06 2026-06-12')
        ->and($series)->toContain('preliminary_tax:2026-08 2026-08-17', 'preliminary_tax:2027-01 2027-01-18')
        ->and($series[11])->toBe('preliminary_tax:2027-05 2027-05-12');

    $topUp = collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2027-05-01'), 2))->firstWhere('key', 'preliminary_tax_top_up:2027-04-30');
    expect($topUp->date->toDateString())->toBe('2027-06-14')->and($topUp->isRecommendation)->toBeTrue();

    ($this->set)(['legal_form' => LegalForm::Hb]);
    expect(($this->dates)('2026-11-04', 15, DeadlineType::PreliminaryTax))->toBe([]);
    ($this->set)(['legal_form' => LegalForm::Ab, 'f_skatt' => false]);
    expect(($this->dates)('2026-11-04', 15, DeadlineType::PreliminaryTax))->toBe([]);
});

it('dates the income tax return, the meeting, the annual report and the closing for a calendar year (REGLER.md §4–6, §9, §11)', function () {
    ($this->set)(['legal_form' => LegalForm::Ab]);
    $byType = fn (): array => collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2027-01-01')))
        ->filter(fn (Deadline $deadline): bool => str_ends_with($deadline->key, ':2026-12-31') && in_array($deadline->type, [DeadlineType::IncomeTaxReturn, DeadlineType::AnnualMeeting, DeadlineType::AnnualReport, DeadlineType::Closing], true))
        ->mapWithKeys(fn (Deadline $deadline): array => [$deadline->type->value => $deadline])->all();

    $dates = $byType();
    expect($dates['income_tax_return']->date->toDateString())->toBe('2027-08-02')
        ->and($dates['income_tax_return']->legalVerify)->toBeFalse()
        ->and($dates['annual_meeting']->date->toDateString())->toBe('2027-06-30')
        ->and($dates['annual_meeting']->legalVerify)->toBeFalse()
        ->and($dates['annual_report']->date->toDateString())->toBe('2027-07-31')
        ->and($dates['annual_report']->legalVerify)->toBeTrue()
        ->and($dates['closing']->date->toDateString())->toBe('2027-03-31')
        ->and($dates['closing']->isRecommendation)->toBeTrue();

    ($this->set)(['legal_form' => LegalForm::Ef]);
    $dates = $byType();
    expect($dates['income_tax_return']->date->toDateString())->toBe('2027-05-03')
        ->and($dates)->not->toHaveKey('annual_meeting')
        ->and($dates)->not->toHaveKey('annual_report')
        ->and($dates['closing']->date->toDateString())->toBe('2027-04-03');

    ($this->set)(['legal_form' => LegalForm::Hb]);
    $dates = $byType();
    expect($dates['income_tax_return']->date->toDateString())->toBe('2027-08-02')
        ->and($dates['income_tax_return']->legalVerify)->toBeFalse()
        ->and($dates)->not->toHaveKey('annual_meeting');

    ($this->set)(['legal_form' => LegalForm::Stiftelse]);
    expect($byType())->toBe([]);
});

it('dates the year-end dates of a broken fiscal year (REGLER.md §11)', function () {
    FiscalYear::query()->delete();
    FiscalYear::create(['start_date' => '2026-05-01', 'end_date' => '2027-04-30']);
    ($this->set)(['legal_form' => LegalForm::Ab, 'fiscal_year_start_month' => 5]);

    $dates = collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2027-05-01')))
        ->filter(fn (Deadline $deadline): bool => str_ends_with($deadline->key, ':2027-04-30') && ! str_starts_with($deadline->key, 'preliminary'))
        ->mapWithKeys(fn (Deadline $deadline): array => [$deadline->type->value => $deadline])->all();

    expect($dates['income_tax_return']->date->toDateString())->toBe('2027-12-01')
        ->and($dates['annual_meeting']->date->toDateString())->toBe('2027-10-30')
        ->and($dates['annual_meeting']->legalVerify)->toBeFalse()
        ->and($dates['annual_report']->date->toDateString())->toBe('2027-11-30')
        ->and($dates['annual_report']->legalVerify)->toBeTrue();
});

it('counts the stämma\'s six months to the same day number for a year ending 30 June (lag (1930:173) 1 §)', function () {
    FiscalYear::query()->delete();
    FiscalYear::create(['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
    ($this->set)(['legal_form' => LegalForm::Ab, 'fiscal_year_start_month' => 7]);

    $meeting = collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2027-07-01')))->firstWhere('key', 'annual_meeting:2027-06-30');
    $report = collect($this->deadlines->upcoming($this->company, CarbonImmutable::parse('2027-07-01')))->firstWhere('key', 'annual_report:2027-06-30');

    expect($meeting->date->toDateString())->toBe('2027-12-30')
        ->and($meeting->legalVerify)->toBeFalse()
        ->and($report->date->toDateString())->toBe('2028-01-30');
});

it('dates periodisk sammanställning on the 25th after a period with EU sales only (REGLER.md §7, §11)', function () {
    ($this->set)(['vat_registered' => true, 'vat_period' => VatPeriod::Quarterly]);
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kunde GmbH', 'payment_terms_days' => 30, 'country' => 'DE', 'vat_number' => 'DE123456789']);

    expect(($this->dates)('2027-01-01', 3, DeadlineType::EuSalesList))->toBe([]);

    Invoice::query()->insert([
        'id' => (string) Str::uuid(),
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'status' => 'ISSUED',
        'number' => 9001,
        'issued_at' => now(),
        'invoice_date' => '2027-01-14',
        'due_date' => '2027-02-13',
        'foreign_sale' => ForeignSale::EuGoods->value,
        'kind' => Invoice::INVOICE,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A new request: the engine keeps its answer for the request (CompanyDeadlines is scoped).
    app()->forgetScopedInstances();
    $this->deadlines = app(CompanyDeadlines::class);

    expect(($this->dates)('2027-01-01', 3, DeadlineType::EuSalesList))->toBe(['eu_sales_list:2027-01 2027-02-25']);
})->skip(fn (): bool => ! defined(Invoice::class.'::INVOICE'), 'Invoice kinds are named differently.');

it('moves a date that falls on a weekend, a public holiday or one of the three eves (REGLER.md §0.1)', function () {
    expect(Workdays::next(CarbonImmutable::parse('2027-07-31'))->toDateString())->toBe('2027-08-02')
        ->and(Workdays::next(CarbonImmutable::parse('2026-12-24'))->toDateString())->toBe('2026-12-28')
        ->and(Workdays::next(CarbonImmutable::parse('2026-12-31'))->toDateString())->toBe('2027-01-04')
        ->and(Workdays::next(CarbonImmutable::parse('2027-06-25'))->toDateString())->toBe('2027-06-28')
        ->and(Workdays::next(CarbonImmutable::parse('2028-07-01'))->toDateString())->toBe('2028-07-03')
        ->and(Workdays::next(CarbonImmutable::parse('2027-01-31'))->toDateString())->toBe('2027-02-01')
        ->and(Workdays::next(CarbonImmutable::parse('2027-02-12'))->toDateString())->toBe('2027-02-12')
        ->and(Workdays::before(CarbonImmutable::parse('2027-02-15'))->toDateString())->toBe('2027-02-12');
});

it('reads the dates with a fixed number of queries, whatever the span', function () {
    ($this->set)(['legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Monthly, 'employer_registered' => true, 'f_skatt' => true]);

    $count = function (int $months): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->deadlines->upcoming($this->company->fresh(), CarbonImmutable::parse('2026-11-04'), $months);

        return count(DB::getQueryLog());
    };

    $count(1);

    expect($count(2))->toBe($count(15));
});
