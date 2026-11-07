<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\AnnualReport\SavePriorYearSizeFigures;
use App\Domain\AnnualReport\K3CompanySize;
use App\Domain\AnnualReport\K3SizeClass;
use App\Domain\Company\LegalForm;
use App\Models\AnnualReport;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\PriorYearSizeFigure;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * Where the size figures come from (ADR-059, addendum 2026-11-07, A.2.5):
 * a year kept in NONOX is read from the ledger and the annual report; a year
 * before the company's first NONOX year is entered by a person.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    $this->year2026 = FiscalYear::query()->whereYear('start_date', 2026)->firstOrFail();
    $this->year2027 = app(CreateNextFiscalYear::class)($this->owner, $this->company);
});

function enterFigures(array $overrides = []): PriorYearSizeFigure
{
    return app(SavePriorYearSizeFigures::class)(test()->owner, test()->company, array_merge([
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'average_employees_fte' => '30',
        'balance_sheet_total' => '45 753 040',
        'net_turnover' => '90 000 000',
    ], $overrides));
}

it('S1-R9 reads a NONOX year from the ledger and the annual report, and an earlier year from the entered figures', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 9_000_000_000, 3001 => -9_000_000_000]);
    AnnualReport::create(['fiscal_year_id' => $this->year2026->id, 'average_employees' => 30, 'average_employees_fte' => '30.50', 'dividend_ore' => 0, 'signatories' => [], 'updated_by' => $this->owner->id]);
    enterFigures();

    $years = K3CompanySize::priorYears($this->year2027);

    expect($years)->toHaveCount(2)
        ->and($years[0]->source)->toBe('ledger')
        ->and($years[0]->netTurnoverOre)->toBe(9_000_000_000)
        ->and($years[0]->balanceSheetTotalOre)->toBe(9_000_000_000)
        ->and((string) $years[0]->averageEmployees)->toBe('30.50')
        ->and($years[1]->source)->toBe('entered')
        ->and($years[1]->balanceSheetTotalOre)->toBe(4_575_304_000)
        ->and((string) $years[1]->averageEmployees)->toBe('30.00')
        ->and(K3CompanySize::forFiscalYear($this->year2027)->class)->toBe(K3SizeClass::Storre);
});

it('S1-R9.2 uses the whole-number employee figure of the annual report when no full-time equivalent is given', function () {
    AnnualReport::create(['fiscal_year_id' => $this->year2026->id, 'average_employees' => 4, 'dividend_ore' => 0, 'signatories' => [], 'updated_by' => $this->owner->id]);

    expect((string) K3CompanySize::priorYears($this->year2027)[0]->averageEmployees)->toBe('4');
});

it('S1-R9.1 a NONOX year without an annual report has no employee figure', function () {
    expect(K3CompanySize::priorYears($this->year2027)[0]->averageEmployees)->toBeNull();
});

it('S1-R9.1 is undetermined while the year before the first NONOX year is missing', function () {
    $result = K3CompanySize::forFiscalYear($this->year2027);

    expect($result->class)->toBe(K3SizeClass::Undetermined)
        ->and($result->reason)->toContain('2025');
});

it('S1-R9 classifies F-S1-a\'s figures entered for the two years before the first NONOX year', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-a/expected.json')), true, flags: JSON_THROW_ON_ERROR)['size_classification']['years'];

    foreach ([2024, 2025] as $year) {
        PriorYearSizeFigure::create([
            'start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31",
            'average_employees_fte' => (string) $fixture[(string) $year]['medelantal_anstallda'],
            'balance_sheet_total_ore' => $fixture[(string) $year]['balansomslutning'],
            'net_turnover_ore' => $fixture[(string) $year]['nettoomsattning'],
            'entered_by' => $this->owner->id,
        ]);
    }

    expect(K3CompanySize::forFiscalYear($this->year2026)->class)->toBe(K3SizeClass::Storre);
});

it('S1-R9 saves prior-year figures, parses Swedish amounts and writes an audit entry with amounts only', function () {
    $figures = enterFigures(['average_employees_fte' => '3,4']);

    expect($figures->balance_sheet_total_ore->ore)->toBe(4_575_304_000)
        ->and($figures->net_turnover_ore->ore)->toBe(9_000_000_000)
        ->and($figures->average_employees_fte)->toBe('3.40');

    $audit = AuditLog::query()->where('action', 'prior_year_size_figures.saved')->latest('id')->firstOrFail();

    expect($audit->payload)->toEqual([
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'average_employees_fte' => '3.40',
        'balance_sheet_total_ore' => 4_575_304_000,
        'net_turnover_ore' => 9_000_000_000,
    ]);
});

it('S1-R9 updates the figures for the same year instead of adding a second row', function () {
    enterFigures();
    enterFigures(['net_turnover' => '1 000']);

    expect(PriorYearSizeFigure::query()->count())->toBe(1)
        ->and(PriorYearSizeFigure::query()->firstOrFail()->net_turnover_ore->ore)->toBe(100_000);
});

it('S1-R9 refuses figures for a year that is kept in NONOX', function () {
    enterFigures(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
})->throws(ValidationException::class);

it('S1-R9 refuses a year that does not start on the first day or end on the last day of a month', function (string $start, string $end) {
    enterFigures(['start_date' => $start, 'end_date' => $end]);
})->with([
    'starts mid-month' => ['2025-01-15', '2025-12-31'],
    'ends mid-month' => ['2025-01-01', '2025-12-15'],
    'longer than 18 months' => ['2023-01-01', '2025-12-31'],
    'ends before it starts' => ['2025-12-01', '2025-01-31'],
])->throws(ValidationException::class);

it('S1-R9 refuses negative balance sheet totals and employee figures with more than two decimals', function (array $input) {
    enterFigures($input);
})->with([
    'negative balance sheet total' => [['balance_sheet_total' => '-1']],
    'three decimals' => [['average_employees_fte' => '3,405']],
    'negative employees' => [['average_employees_fte' => '-1']],
    'an amount too large to store' => [['net_turnover' => '999 999 999 999 999 999 999']],
])->throws(ValidationException::class);

it('S1-R9 lets owner, admin and accountant enter prior-year figures, not a bookkeeper or an auditor', function (string $role, bool $allowed) {
    $user = Bookkeeping::member($this->company, $role);

    $attempt = fn () => app(SavePriorYearSizeFigures::class)($user, $this->company, [
        'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'average_employees_fte' => '1',
        'balance_sheet_total' => '1', 'net_turnover' => '1',
    ]);

    $allowed
        ? expect($attempt())->toBeInstanceOf(PriorYearSizeFigure::class)
        : expect($attempt)->toThrow(AuthorizationException::class);
})->with([
    'admin' => ['admin', true],
    'accountant' => ['accountant', true],
    'bookkeeper' => ['bookkeeper', false],
    'approver' => ['approver', false],
    'auditor' => ['auditor', false],
]);

it('S1-R9.1 classifies the first NONOX year of a confirmed newly started company as mindre', function () {
    expect(K3CompanySize::forFiscalYear($this->year2026)->class)->toBe(K3SizeClass::Undetermined)
        ->and(K3CompanySize::forFiscalYear($this->year2026, confirmedFirstYearStart: CarbonImmutable::parse('2026-01-01'))->class)->toBe(K3SizeClass::Mindre)
        ->and(K3CompanySize::forFiscalYear($this->year2027, confirmedFirstYearStart: CarbonImmutable::parse('2026-01-01'))->class)->toBe(K3SizeClass::Mindre)
        ->and(K3CompanySize::k2ExceptionForFiscalYear($this->year2027, CarbonImmutable::parse('2026-01-01'))->applies)->toBeTrue();
});

it('S1-R9 reads the legal form of the company: a stiftelse is left to a person', function () {
    $this->company->forceFill(['legal_form' => LegalForm::Stiftelse])->save();
    enterFigures(['start_date' => '2024-01-01', 'end_date' => '2024-12-31']);
    enterFigures();

    expect(K3CompanySize::forFiscalYear($this->year2026)->class)->toBe(K3SizeClass::Undetermined);
});
