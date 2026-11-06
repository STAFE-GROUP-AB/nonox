<?php

declare(strict_types=1);

use App\Actions\IncomeTax\K10Worksheets;
use App\Domain\Accounting\AccountType;
use App\Domain\Company\LegalForm;
use App\Domain\IncomeTax\K10;
use App\Domain\IncomeTax\K10Books;
use App\Domain\IncomeTax\K10Input;
use App\Domain\IncomeTax\K10Parameters;
use App\Livewire\Accounting\K10Page;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\K10Owner;
use App\Models\K10Year;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * K10 underlag (ADR-062) under the gränsbelopp rules from inkomstår 2026
 * (IL 57 kap. as amended by SFS 2025:1361). The examples are Skatteverket's
 * own ("Ändrade regler för delägare i fåmansföretag inför
 * inkomstdeklarationen 2027") and hand-computed ones with 2026's parameters:
 * inkomstbasbelopp 2025 80 600 kr (grundbelopp 322 400, löneavdrag 644 800),
 * statslåneränta 2,55 % (ränta 11,55 %), 90 inkomstbasbelopp 2026 7 506 000.
 */

function k10Calc(array $overrides = []): K10
{
    $parameters = K10Parameters::for(2026);
    expect($parameters)->not->toBeNull();

    return K10::calculate($parameters, new K10Input(...[
        'sharesOwned' => 100,
        'sharesTotal' => 100,
        'otherHoldingsBp' => null,
        'spouseShares' => 0,
        'acquiredDuringPreviousYear' => false,
        'companyPayKronor' => 0,
        'subsidiaryPayKronor' => 0,
        'capBasisKronor' => 0,
        'costBasisKronor' => 0,
        'savedSpaceKronor' => 0,
        'dividendKronor' => 0,
        ...$overrides,
    ]));
}

it('has sourced 2026 parameters and none for years without a file', function () {
    $parameters = K10Parameters::for(2026);

    expect($parameters?->basicAmountOre())->toBe(32_240_000)
        ->and($parameters?->salaryDeductionOre())->toBe(64_480_000)
        ->and($parameters?->costBasisInterestBp())->toBe(1155)
        ->and($parameters?->employmentIncomeCapOre())->toBe(750_600_000)
        ->and($parameters?->period)->toBe('2026P4')
        ->and(K10Parameters::for(2025))->toBeNull()
        ->and(K10Parameters::for(2027))->toBeNull()
        ->and(K10Parameters::years())->toBe([2026]);
});

it('computes Skatteverket\'s example for Valter: grundbelopp, lönebaserat utrymme, sparat utrymme and a dividend within the limit', function () {
    $k10 = k10Calc(['companyPayKronor' => 1_000_000, 'capBasisKronor' => 600_000, 'costBasisKronor' => 25_000, 'savedSpaceKronor' => 750_000, 'dividendKronor' => 100_000]);

    expect($k10->points())->toMatchArray([
        '1.1' => 322_400, '1.2' => 177_600, '1.3' => 0, '1.4' => 750_000, '1.6' => 1_250_000,
        '4.1' => 1_000_000, '4.3' => 1_000_000, '4.4' => 1_000_000, '4.9' => 1_000_000, '4.10' => 644_800, '4.11' => 355_200, '4.12' => 177_600,
        '2.1' => 100_000, '2.2' => 1_250_000, '2.3' => 0, '2.4' => 1_150_000, '2.6' => 1_150_000, '2.7' => 100_000, '2.8' => 66_667, '2.9' => 0, '2.10' => 66_667,
    ])->and($k10->savedForNextYearKronor())->toBe(1_150_000)
        ->and($k10->points())->not->toHaveKey('4.13');
});

it('computes Skatteverket\'s example for Agnes and Birger, who are not related', function () {
    $agnes = k10Calc(['sharesOwned' => 70, 'companyPayKronor' => 4_000_000, 'capBasisKronor' => 400_000]);
    $birger = k10Calc(['sharesOwned' => 30, 'companyPayKronor' => 4_000_000, 'capBasisKronor' => 300_000]);

    expect($agnes->point('4.4'))->toBe(2_800_000)->and($agnes->point('1.2'))->toBe(1_077_600)->and($agnes->point('1.1'))->toBe(225_680)
        ->and($birger->point('4.4'))->toBe(1_200_000)->and($birger->point('1.2'))->toBe(277_600)->and($birger->point('1.1'))->toBe(96_720);
});

it('computes the joint calculation for the spouses Amy and Gedion', function () {
    $amy = k10Calc(['sharesOwned' => 60, 'spouseShares' => 40, 'companyPayKronor' => 4_000_000, 'capBasisKronor' => 500_000]);
    $gedion = k10Calc(['sharesOwned' => 40, 'spouseShares' => 60, 'companyPayKronor' => 4_000_000, 'capBasisKronor' => 500_000]);

    expect($amy->point('4.4'))->toBe(4_000_000)->and($amy->point('4.12'))->toBe(1_677_600)
        ->and($amy->point('4.13'))->toBe(1_006_560)->and($amy->point('1.2'))->toBe(1_006_560)
        ->and($gedion->point('4.13'))->toBe(671_040)->and($gedion->point('1.2'))->toBe(671_040);
});

it('computes the interest on the omkostnadsbelopp above 100 000 kr, Skatteverket\'s example for Helle', function () {
    expect(k10Calc(['costBasisKronor' => 250_000])->point('1.3'))->toBe(17_325)
        ->and(k10Calc(['costBasisKronor' => 100_000])->point('1.3'))->toBe(0)
        ->and(k10Calc(['costBasisKronor' => 25_000])->point('1.3'))->toBe(0);
});

it('shares one grundbelopp over the owner\'s fåmansföretag, Skatteverket\'s examples for Tove and Ismail', function (int $owned, int $others, int $expected, bool $shared) {
    $k10 = k10Calc(['sharesOwned' => $owned, 'otherHoldingsBp' => $others]);

    expect($k10->point('1.1'))->toBe($expected)->and($k10->isBasicAmountShared())->toBe($shared);
})->with([
    'Tove, Markisexpert 100 % (others 25 + 75)' => [100, 10_000, 161_200, true],
    'Tove, Bullbageriet 25 % (others 75 + 100)' => [25, 17_500, 40_300, true],
    'Tove, Tullärt 75 % (others 25 + 100)' => [75, 12_500, 120_900, true],
    'Ismail, Spjutvision 25 % (other 33)' => [25, 3_300, 80_600, false],
    'no other company' => [100, 0, 322_400, false],
]);

it('taxes a dividend above the limit as tjänst and two thirds of the rest as kapital', function () {
    $k10 = k10Calc(['dividendKronor' => 500_000]);

    // 500 000 − 322 400 = 177 600 tjänst; 322 400 × 2/3 = 214 933,33 → 214 933.
    expect($k10->points())->toMatchArray(['1.6' => 322_400, '2.3' => 177_600, '2.4' => 0, '2.7' => 322_400, '2.8' => 214_933, '2.9' => 0, '2.10' => 214_933])
        ->and($k10->savedForNextYearKronor())->toBe(0)
        ->and($k10->isEmploymentIncomeCapped())->toBeFalse();
});

it('caps the tjänst part at 90 inkomstbasbelopp and taxes the rest in full as kapital', function () {
    $k10 = k10Calc(['dividendKronor' => 10_000_000]);

    // 10 000 000 − 322 400 = 9 677 600 > 7 506 000; 10 000 000 − 7 506 000 − 322 400 = 2 171 600.
    expect($k10->points())->toMatchArray(['2.3' => 7_506_000, '2.7' => 322_400, '2.8' => 214_933, '2.9' => 2_171_600, '2.10' => 2_386_533])
        ->and($k10->isEmploymentIncomeCapped())->toBeTrue();
});

it('caps the lönebaserat utrymme at 50 times the highest cash pay to the owner or a närstående', function () {
    $k10 = k10Calc(['companyPayKronor' => 4_000_000, 'capBasisKronor' => 10_000]);
    $none = k10Calc(['companyPayKronor' => 4_000_000, 'capBasisKronor' => 0]);

    expect($k10->point('4.12'))->toBe(1_677_600)->and($k10->point('1.2'))->toBe(500_000)->and($k10->isSalarySpaceCapped())->toBeTrue()
        ->and($none->point('1.2'))->toBe(0);
});

it('adds no lönebaserat utrymme below the löneavdrag and leaves the negative points out of the file', function () {
    $k10 = k10Calc(['companyPayKronor' => 300_000, 'capBasisKronor' => 300_000]);
    $fields = $k10->fields('165566778899');

    expect($k10->point('4.11'))->toBe(-344_800)->and($k10->point('4.12'))->toBe(0)->and($k10->point('1.2'))->toBe(0)
        ->and($fields)->toHaveKey(4570)->toHaveKey(4585)->not->toHaveKey(4586)->not->toHaveKey(4587)->not->toHaveKey(4521);
});

it('puts the pay of shares acquired during the year before in points 4.5–4.8', function () {
    $k10 = k10Calc(['acquiredDuringPreviousYear' => true, 'companyPayKronor' => 2_000_000, 'subsidiaryPayKronor' => 100_000, 'capBasisKronor' => 500_000, 'sharesOwned' => 50]);

    expect($k10->points())->toMatchArray(['4.5' => 2_000_000, '4.6' => 100_000, '4.7' => 2_100_000, '4.8' => 1_050_000, '4.9' => 1_050_000, '4.11' => 405_200, '4.12' => 202_600, '1.2' => 202_600])
        ->and($k10->points())->not->toHaveKey('4.1')->not->toHaveKey('4.4');
});

it('carries the whole limit to next year when there is no dividend', function () {
    $k10 = k10Calc(['savedSpaceKronor' => 10_000]);

    expect($k10->hasDividend())->toBeFalse()->and($k10->savedForNextYearKronor())->toBe(332_400)
        ->and($k10->points())->not->toHaveKey('2.1')
        ->and($k10->fields('165566778899'))->not->toHaveKey(4525)->not->toHaveKey(4734);
});

it('refuses shares outside the company\'s shares', function () {
    expect(fn () => k10Calc(['sharesOwned' => 101]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => k10Calc(['sharesOwned' => 60, 'spouseShares' => 50]))->toThrow(InvalidArgumentException::class);
});

function k10PreviousYear(): void
{
    FiscalYear::query()->firstOrCreate(['start_date' => '2025-01-01'], ['end_date' => '2025-12-31']);
}

function k10Ledger(object $test): void
{
    k10PreviousYear();
    // 2025: salaries 600 000 + 400 000 to the företagsledare; the 20 000 board fee, accruals and benefits stay out.
    Bookkeeping::postRows($test->owner, $test->company, '2025-03-25', [7210 => 60_000_000, 7220 => 40_000_000, 7240 => 2_000_000, 1930 => -102_000_000]);
    Bookkeeping::postRows($test->owner, $test->company, '2025-12-31', [7090 => 5_000_000, 7385 => 2_000_000, 2910 => -7_000_000]);
    // 2026: a dividend of 200 000 decided and paid.
    Bookkeeping::postRows($test->owner, $test->company, '2026-05-10', [2091 => 20_000_000, 2898 => -20_000_000]);
    Bookkeeping::postRows($test->owner, $test->company, '2026-05-20', [2898 => 15_000_000, 1930 => -15_000_000]);
}

function k10Owner(object $test, array $input = []): K10Owner
{
    return $test->worksheets->saveOwner($test->owner, $test->company, 2026, ['name' => 'Valter Rönn', 'shares_owned' => '100', ...$input]);
}

function k10PayrollRun(object $test, int $employeeId, string $paid, int $ore): void
{
    $employment = Employment::query()->where('employee_id', $employeeId)->firstOrFail();
    $run = PayrollRun::query()->forceCreate(['company_id' => $test->company->id, 'period' => substr($paid, 0, 7), 'payment_date' => $paid, 'type' => 'REGULAR', 'status' => PayrollRun::DRAFT]);
    $payslip = Payslip::query()->forceCreate(['company_id' => $test->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $employeeId, 'employment_id' => $employment->id]);
    PayslipLine::query()->forceCreate(['company_id' => $test->company->id, 'payslip_id' => $payslip->id, 'pay_code_id' => (int) PayCode::query()->where('code', 'MANADSLON')->value('id'), 'description' => 'Månadslön', 'amount_ore' => $ore, 'tax_effect' => 'TABLE', 'explanation' => []]);
    // Payslips are fixed once a run is approved (a trigger), so the run is approved after its line.
    $run->forceFill(['status' => PayrollRun::POSTED])->save();
}

describe('from the books', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
        [$this->owner, $this->company] = Bookkeeping::company();
        $this->company->forceFill(['legal_form' => LegalForm::Ab, 'org_number' => '556677-8899', 'legal_name' => 'Rönnverket AB', 'postal_code' => '111 22', 'city' => 'Stockholm'])->save();
        CurrentCompany::set($this->company);
        $this->actingAs($this->owner);
        foreach ([7090 => ['Förändring av semesterlöneskuld', AccountType::Expense], 7385 => ['Kostnader för fri bil', AccountType::Expense], 7240 => ['Styrelsearvoden', AccountType::Expense], 2898 => ['Outtagen vinstutdelning', AccountType::LiabilityEquity], 3988 => ['Erhållna offentliga bidrag för personal', AccountType::Revenue]] as $number => [$name, $type]) {
            Account::query()->firstOrCreate(['number' => $number], ['name' => $name, 'type' => $type, 'is_active' => true]);
        }

        $this->worksheets = app(K10Worksheets::class);
    });

    it('proposes the löneunderlag from the salary accounts and the dividend from 2898', function () {
        k10Ledger($this);
        $books = K10Books::for($this->company, 2026);

        expect($books->source)->toBe(K10Books::SOURCE_LEDGER)
            ->and($books->companyPayKronor())->toBe(1_000_000)
            ->and($books->companyPayKronor(CarbonImmutable::parse('2025-04-01')))->toBe(0)
            ->and($books->leaderPayKronor())->toBe(400_000)
            ->and($books->dividendDecidedKronor())->toBe(200_000)
            ->and($books->dividendPaidKronor())->toBe(150_000)
            ->and($books->warnings())->toHaveCount(1);
    });

    it('says when the year before is not in the books, and warns about wage grants', function () {
        expect(implode(' ', K10Books::for($this->company, 2026)->warnings()))->toContain('Bokföringen för 2025 finns inte i NONOX');

        k10PreviousYear();
        Bookkeeping::postRows($this->owner, $this->company, '2025-06-01', [1930 => 3_000_000, 3988 => -3_000_000]);
        $books = K10Books::for($this->company, 2026);

        expect($books->grantsKronor())->toBe(30_000)->and(implode(' ', $books->warnings()))->toContain('3988')->not->toContain('finns inte i NONOX');
    });

    it('takes the cash pay from payroll runs paid in the year before, without benefits and allowances', function () {
        k10PreviousYear();
        Payroll::setUp($this->company, $this->owner);
        $employee = Payroll::employee($this->owner, $this->company);
        $employment = Employment::query()->where('employee_id', $employee->id)->firstOrFail();
        $code = fn (string $key): int => (int) PayCode::query()->where('code', $key)->value('id');

        $run = PayrollRun::query()->forceCreate(['company_id' => $this->company->id, 'period' => '2025-06', 'payment_date' => '2025-06-25', 'type' => 'REGULAR', 'status' => PayrollRun::DRAFT]);
        $payslip = Payslip::query()->forceCreate(['company_id' => $this->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'employment_id' => $employment->id]);

        foreach ([['MANADSLON', 5_000_000, 'TABLE'], ['BONUS', 1_000_000, 'ONE_OFF'], ['BILFORMAN', 500_000, 'TABLE'], ['TRAKT_SKATTEPLIKTIG', 100_000, 'TABLE'], ['MILERSATTNING', 200_000, 'NONE']] as [$key, $ore, $effect]) {
            PayslipLine::query()->forceCreate(['company_id' => $this->company->id, 'payslip_id' => $payslip->id, 'pay_code_id' => $code($key), 'description' => $key, 'amount_ore' => $ore, 'tax_effect' => $effect, 'explanation' => $key === 'BILFORMAN' ? ['benefit' => true] : []]);
        }

        // Payslips are fixed once a run is approved (a trigger), so the run is approved after its lines.
        $run->forceFill(['status' => PayrollRun::POSTED])->save();
        PayrollRun::query()->forceCreate(['company_id' => $this->company->id, 'period' => '2025-07', 'payment_date' => '2025-07-25', 'type' => 'REGULAR', 'status' => PayrollRun::DRAFT]);
        $books = K10Books::for($this->company, 2026);

        expect($books->source)->toBe(K10Books::SOURCE_PAYROLL)
            ->and($books->companyPayKronor())->toBe(60_000)
            ->and($books->employeePayKronor($employee->id))->toBe(60_000)
            ->and($books->companyPayKronor(CarbonImmutable::parse('2025-07-01')))->toBe(0)
            ->and(implode(' ', $books->warnings()))->toContain('inte är attesterade');

        // Linked to the employee, the owner's own pay comes from payroll.
        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        $owner = k10Owner($this, ['employee_id' => (string) $employee->id]);
        $sheet = $this->worksheets->sheets($this->owner, $this->company, 2026)->get($owner->id);

        expect($sheet->input()?->capBasisKronor)->toBe(60_000)->and($sheet->origin('own_pay'))->toBe('payroll');
    });

    it('builds the worksheet from the books and what the owner enters', function () {
        k10Ledger($this);
        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        $owner = k10Owner($this, ['own_pay' => '400 000', 'cost_basis' => '250 000', 'saved_space' => '50 000', 'other_holdings' => '0']);
        $sheet = $this->worksheets->sheets($this->owner, $this->company, 2026)->get($owner->id);
        $k10 = $sheet->k10();

        // (1 000 000 − 644 800) × 0,5 = 177 600; 150 000 × 11,55 % = 17 325; 322 400 + 177 600 + 17 325 + 50 000 = 567 325.
        expect($sheet->blockers())->toBe([])
            ->and($k10?->points())->toMatchArray(['1.1' => 322_400, '1.2' => 177_600, '1.3' => 17_325, '1.4' => 50_000, '1.6' => 567_325, '2.1' => 200_000, '2.4' => 367_325, '2.8' => 133_333])
            ->and($sheet->origin('company_pay'))->toBe('ledger')->and($sheet->origin('dividend'))->toBe('ledger');
    });

    it('subtracts wage grants and uses an entered company dividend', function () {
        k10Ledger($this);
        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '200', 'wage_subsidy' => '20 000', 'dividend_total' => '300 000', 'qualified_confirmed' => true]);
        $owner = k10Owner($this, ['own_pay' => '400 000']);
        $input = $this->worksheets->sheets($this->owner, $this->company, 2026)->get($owner->id)?->input();

        expect($input?->companyPayKronor)->toBe(980_000)->and($input?->dividendKronor)->toBe(150_000);
    });

    it('blocks the calculation until the shares are confirmed as qualified and counted, and refuses sold shares and new owners', function () {
        $owner = k10Owner($this);
        $sheet = fn () => $this->worksheets->sheets($this->owner, $this->company, 2026)->get($owner->id);

        expect($sheet()->k10())->toBeNull()->and(implode(' ', $sheet()->blockers()))->toContain('fåmansföretag')->toContain('aktier företaget hade');

        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        expect($sheet()->k10())->not->toBeNull();

        $this->worksheets->saveOwner($this->owner, $this->company, 2026, ['name' => 'Valter Rönn', 'shares_owned' => '100', 'transferred_shares' => true], $owner->id);
        expect($sheet()->k10())->toBeNull();

        $this->worksheets->saveOwner($this->owner, $this->company, 2026, ['name' => 'Valter Rönn', 'shares_owned' => '100', 'acquired_on' => '2026-02-01'], $owner->id);
        expect(implode(' ', $sheet()->blockers()))->toContain('ägde aktierna vid årets ingång');
    });

    it('links spouses both ways and computes them jointly', function () {
        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        $amy = k10Owner($this, ['name' => 'Amy', 'shares_owned' => '60', 'own_pay' => '500 000', 'company_pay' => '4 000 000']);
        $gedion = k10Owner($this, ['name' => 'Gedion', 'shares_owned' => '40', 'own_pay' => '300 000', 'company_pay' => '4 000 000', 'spouse_owner_id' => (string) $amy->id]);
        $sheets = $this->worksheets->sheets($this->owner, $this->company, 2026);

        expect($amy->fresh()->spouse_owner_id)->toBe($gedion->id)
            ->and($sheets->get($amy->id)?->k10()?->point('1.2'))->toBe(1_006_560)
            ->and($sheets->get($gedion->id)?->k10()?->point('1.2'))->toBe(671_040)
            ->and($sheets->get($gedion->id)?->input()?->capBasisKronor)->toBe(500_000);

        $this->worksheets->deleteOwner($this->owner, $this->company, $amy->fresh());
        expect($gedion->fresh()->spouse_owner_id)->toBeNull();
    });

    it('stores the personnummer encrypted, keeps it when the field is left empty, and logs no names, numbers or amounts', function () {
        $owner = k10Owner($this, ['identity' => '19121212-1212', 'cost_basis' => '250 000']);
        $this->worksheets->saveOwner($this->owner, $this->company, 2026, ['name' => 'Valter Rönn', 'shares_owned' => '100', 'identity' => ''], $owner->id);
        $payload = json_encode(AuditLog::query()->where('action', 'k10.owner_saved')->get()->pluck('payload'));

        expect($owner->fresh()->identity)->toBe('191212121212')
            ->and($owner->fresh()->maskedIdentity())->toBe('••••••••-1212')
            ->and((string) DB::table('k10_owners')->where('id', $owner->id)->value('identity'))->not->toContain('1912')
            ->and($owner->fresh()->toArray())->not->toHaveKey('identity')
            ->and($payload)->not->toContain('1912')->not->toContain('Valter')->not->toContain('250');

        expect(fn () => k10Owner($this, ['identity' => '19121212-1213']))->toThrow(ValidationException::class);
    });

    it('writes one owner\'s K10 for 2026P4 to an SRU file, only with a personnummer', function () {
        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        $owner = k10Owner($this, ['company_pay' => '1 000 000', 'own_pay' => '600 000', 'cost_basis' => '25 000', 'saved_space' => '750 000', 'dividend' => '100 000']);

        expect(fn () => $this->worksheets->sruZip($this->owner, $this->company, $owner))->toThrow(ValidationException::class);

        $owner = $this->worksheets->saveOwner($this->owner, $this->company, 2026, ['name' => 'Valter Rönn', 'shares_owned' => '100', 'identity' => '191212121212', 'company_pay' => '1 000 000', 'own_pay' => '600 000', 'cost_basis' => '25 000', 'saved_space' => '750 000', 'dividend' => '100 000'], $owner->id);
        $files = Bookkeeping::unzipSru($this->worksheets->sruZip($this->owner, $this->company, $owner));
        $blanketter = $files['BLANKETTER.SRU'];

        expect($files['INFO.SRU'])->toContain("#ORGNR 165566778899\n")
            ->and($blanketter)->toStartWith("#BLANKETT K10-2026P4\n#IDENTITET 191212121212 ")
            ->and($blanketter)->toContain("#UPPGIFT 4530 165566778899\n#UPPGIFT 4531 100\n#UPPGIFT 4532 100\n")
            ->and($blanketter)->toContain("#UPPGIFT 4511 322400\n#UPPGIFT 4521 177600\n#UPPGIFT 4522 750000\n#UPPGIFT 4524 1250000\n")
            ->and($blanketter)->toContain("#UPPGIFT 4525 100000\n#UPPGIFT 4731 1250000\n#UPPGIFT 4732 1150000\n#UPPGIFT 4734 1150000\n#UPPGIFT 4736 100000\n#UPPGIFT 4529 66667\n#UPPGIFT 4541 66667\n")
            ->and($blanketter)->toContain("#UPPGIFT 4570 1000000\n#UPPGIFT 4572 1000000\n#UPPGIFT 4576 1000000\n#UPPGIFT 4584 1000000\n#UPPGIFT 4585 644800\n#UPPGIFT 4586 355200\n#UPPGIFT 4587 177600\n")
            ->and($blanketter)->not->toContain('#UPPGIFT 4520 ')->not->toContain('#UPPGIFT 4526 ')->not->toContain('#UPPGIFT 4588 ')
            ->and($blanketter)->toEndWith("#BLANKETTSLUT\n#FIL_SLUT\n")
            ->and(AuditLog::query()->where('action', 'k10.sru_exported')->count())->toBe(1);
    });

    it('shows the worksheet as a page and a PDF with the Beta notice', function () {
        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        $owner = k10Owner($this, ['identity' => '191212121212', 'company_pay' => '1 000 000', 'own_pay' => '600 000']);

        $this->get(route('accounting.k10.show', $owner))->assertOk()
            ->assertSee('K10-underlag inkomstår 2026')->assertSee('Beta')->assertSee('••••••••-1212')->assertDontSee('191212121212')
            ->assertSee('Gränsbelopp')->assertSee('177'."\u{a0}".'600');
        $this->get(route('accounting.k10.pdf', $owner))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('accounting.k10.sru', $owner))->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->get(route('privacy.export.k10Owner', $owner))->assertOk()->assertJsonPath('k10_agare.0.personnummer', '191212121212');
    });

    it('runs a fixed number of queries for the worksheets whatever the number of owners', function () {
        k10Ledger($this);
        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->worksheets->sheets($this->owner, $this->company, 2026)->each(fn ($sheet) => $sheet->k10());
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        k10Owner($this, ['shares_owned' => '10']);
        $one = $count();

        foreach (range(1, 5) as $i) {
            k10Owner($this, ['name' => "Ägare {$i}", 'shares_owned' => '10']);
        }

        expect($count())->toBe($one);
    });

    it('lets the owner, an administratör and a redovisningskonsult in, and nobody else', function (string $role, bool $allowed) {
        $member = Bookkeeping::member($this->company, $role);
        $this->actingAs($member);

        if ($allowed) {
            Livewire::test(K10Page::class)->assertOk()->assertSee('Lägg till ägare');
            expect($this->worksheets->saveYear($member, $this->company, 2026, ['shares_total' => '100']))->toBeInstanceOf(K10Year::class);

            return;
        }

        Livewire::test(K10Page::class)->assertForbidden();
        expect(fn () => $this->worksheets->saveYear($member, $this->company, 2026, ['shares_total' => '100']))->toThrow(AuthorizationException::class);
        $owner = CurrentCompany::run($this->company, fn () => K10Owner::query()->forceCreate(['company_id' => $this->company->id, 'income_year' => 2026, 'name' => 'A', 'shares_owned' => 1, 'updated_by' => $this->owner->id]));
        $this->get(route('accounting.k10.show', $owner))->assertForbidden();
        $this->get(route('privacy.export.k10Owner', $owner))->assertForbidden();
    })->with([
        'admin' => ['admin', true],
        'accountant' => ['accountant', true],
        'bookkeeper' => ['bookkeeper', false],
        'approver' => ['approver', false],
        'auditor' => ['auditor', false],
    ]);

    it('only links a payroll employee for someone who may see payroll', function () {
        Payroll::setUp($this->company, $this->owner);
        $employee = Payroll::employee($this->owner, $this->company);
        $linked = $this->worksheets->saveOwner($this->owner, $this->company, 2026, ['name' => 'Valter', 'shares_owned' => '1', 'employee_id' => (string) $employee->id]);

        expect($linked->employee_id)->toBe($employee->id);

        // With the payroll module off, nobody has payroll:view: a new link is refused, the stored one is kept.
        app(ModuleManager::class)->disable(PayrollModule::KEY, $this->company, $this->owner, true);
        $owner = $this->owner->fresh();

        expect(fn () => $this->worksheets->saveOwner($owner, $this->company, 2026, ['name' => 'Rita', 'shares_owned' => '1', 'employee_id' => (string) $employee->id]))->toThrow(ValidationException::class)
            ->and($this->worksheets->saveOwner($owner, $this->company, 2026, ['name' => 'Valter', 'shares_owned' => '1', 'employee_id' => (string) $employee->id], $linked->id)->employee_id)->toBe($employee->id);
    });

    it('refuses other legal forms and years without parameters', function () {
        expect(fn () => $this->worksheets->saveYear($this->owner, $this->company, 2025, ['shares_total' => '100']))->toThrow(ValidationException::class);

        $this->company->forceFill(['legal_form' => LegalForm::Ef])->save();
        expect(fn () => $this->worksheets->saveYear($this->owner, $this->company->fresh(), 2026, ['shares_total' => '100']))->toThrow(ValidationException::class);
        Livewire::test(K10Page::class)->assertOk()->assertSee('Inte för den här företagsformen')->assertDontSee('Lägg till ägare');
    });

    it('edits, saves and deletes an owner on the page, with the personnummer masked', function () {
        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        $owner = k10Owner($this, ['identity' => '191212121212', 'company_pay' => '1 000 000', 'own_pay' => '600 000']);

        Livewire::test(K10Page::class)
            ->assertSee('Valter Rönn')->assertSee('Beta')->assertSee('Visa underlaget')->assertSee('Ladda ner SRU-fil')
            ->call('editOwner', $owner->id)
            ->assertSet('ownerForm.identity', '')->assertSet('ownerForm.masked', '••••••••-1212')->assertDontSee('191212121212')
            ->set('ownerForm.saved_space', '1 000')
            ->call('saveOwner')->assertHasNoErrors()
            ->call('deleteOwner', $owner->id);

        expect(K10Owner::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'k10.owner_deleted')->count())->toBe(1);
    });

    it('proposes the ledger when payroll runs start in the autumn, and shows both figures', function () {
        k10PreviousYear();
        Payroll::setUp($this->company, $this->owner);
        $employee = Payroll::employee($this->owner, $this->company);
        // 2 400 000 paid January–August outside NONOX's payroll, 1 200 000 September–December through payroll; both booked on 7210.
        Bookkeeping::postRows($this->owner, $this->company, '2025-05-25', [7210 => 240_000_000, 1930 => -240_000_000]);
        Bookkeeping::postRows($this->owner, $this->company, '2025-10-25', [7210 => 120_000_000, 1930 => -120_000_000]);
        k10PayrollRun($this, $employee->id, '2025-10-25', 120_000_000);
        $books = K10Books::for($this->company, 2026);

        expect($books->source)->toBe(K10Books::SOURCE_LEDGER)
            ->and($books->companyPayKronor())->toBe(3_600_000)
            ->and($books->payrollPayKronor())->toBe(1_200_000)
            ->and($books->employeePayKronor($employee->id))->toBe(1_200_000)
            ->and(implode(' ', $books->warnings()))->toContain('1 200 000 kr')->toContain('3 600 000 kr')->toContain('börjar 2025-10-25');
    });

    it('proposes payroll when its runs cover the year from January', function () {
        k10PreviousYear();
        Payroll::setUp($this->company, $this->owner);
        $employee = Payroll::employee($this->owner, $this->company);
        Bookkeeping::postRows($this->owner, $this->company, '2025-01-25', [7210 => 5_100_000, 1930 => -5_100_000]);
        k10PayrollRun($this, $employee->id, '2025-01-25', 5_000_000);
        $books = K10Books::for($this->company, 2026);

        expect($books->source)->toBe(K10Books::SOURCE_PAYROLL)->and($books->companyPayKronor())->toBe(50_000)
            ->and(implode(' ', $books->warnings()))->toContain('51 000 kr')->not->toContain('börjar');
    });

    it('refuses shares held all of the year before and topped up during it, Skatteverket\'s two calculations', function () {
        $this->worksheets->saveYear($this->owner, $this->company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        // 60 shares since 2020 and 20 bought on 2025-07-01: 4.1–4.4 for the 60, 4.5–4.8 for the 20, one löneavdrag.
        $owner = k10Owner($this, ['shares_owned' => '80', 'topped_up_previous_year' => true, 'company_pay' => '4 000 000', 'own_pay' => '500 000']);
        $sheet = $this->worksheets->sheets($this->owner, $this->company, 2026)->get($owner->id);

        expect($sheet?->k10())->toBeNull()->and(implode(' ', $sheet?->blockers() ?? []))->toContain('16 a §')->toContain('Skatteverkets e-tjänst');
    });

    it('keeps the carried-over mark on the saved space until a person changes it', function () {
        $owner = K10Owner::query()->forceCreate(['company_id' => $this->company->id, 'income_year' => 2026, 'name' => 'Valter Rönn', 'shares_owned' => 100, 'saved_space_kronor' => 750_000, 'saved_space_carried' => true, 'updated_by' => $this->owner->id]);

        $kept = $this->worksheets->saveOwner($this->owner, $this->company, 2026, ['name' => 'Valter Rönn', 'shares_owned' => '100', 'saved_space' => '750 000'], $owner->id);
        expect($kept->saved_space_carried)->toBeTrue();

        Livewire::test(K10Page::class)->assertSee('förs över från förra årets underlag');

        $changed = $this->worksheets->saveOwner($this->owner, $this->company, 2026, ['name' => 'Valter Rönn', 'shares_owned' => '100', 'saved_space' => '700 000'], $owner->id);
        expect($changed->saved_space_carried)->toBeFalse();
    });

    it('deletes an owner after a change of legal form, and for a year without parameters', function () {
        $owner = k10Owner($this);
        $old = K10Owner::query()->forceCreate(['company_id' => $this->company->id, 'income_year' => 2025, 'name' => 'Gammal', 'shares_owned' => 1, 'updated_by' => $this->owner->id]);
        $this->company->forceFill(['legal_form' => LegalForm::Ef])->save();
        $company = $this->company->fresh();
        $bookkeeper = Bookkeeping::member($company, 'bookkeeper');

        expect(fn () => $this->worksheets->deleteOwner($bookkeeper, $company, $owner))->toThrow(AuthorizationException::class);

        $this->worksheets->deleteOwner($this->owner, $company, $owner);
        $this->worksheets->deleteOwner($this->owner, $company, $old);

        expect(K10Owner::query()->count())->toBe(0);
    });

    it('checks the permission on every request of the page and in the worksheets', function () {
        $admin = Bookkeeping::member($this->company, 'admin');
        $owner = k10Owner($this);
        $this->actingAs($admin);
        $page = Livewire::test(K10Page::class)->assertOk();

        // The role changes while the page is open.
        $this->company->users()->updateExistingPivot($admin->id, ['role' => 'bookkeeper']);
        $bookkeeper = $admin->fresh();

        // LivewireCompanyGuard answers 404 for a changed role before the component's own check (403) runs.
        $page->call('editOwner', $owner->id)->assertNotFound();
        $this->actingAs($bookkeeper);
        Livewire::test(K10Page::class)->assertForbidden();
        expect(fn () => $this->worksheets->sheets($bookkeeper, $this->company, 2026))->toThrow(AuthorizationException::class);
    });

    it('copies last year\'s owners to start the year', function () {
        $old = K10Owner::query()->forceCreate(['company_id' => $this->company->id, 'income_year' => 2025, 'name' => 'Valter Rönn', 'identity' => '191212121212', 'shares_owned' => 100, 'cost_basis_kronor' => 25_000, 'updated_by' => $this->owner->id]);

        expect($this->worksheets->copyFromPreviousYear($this->owner, $this->company, 2026))->toBe(1);
        $copy = K10Owner::query()->where('income_year', 2026)->sole();

        expect($copy->name)->toBe('Valter Rönn')->and($copy->identity)->toBe('191212121212')->and($copy->cost_basis_kronor)->toBeNull()->and($copy->saved_space_carried)->toBeFalse()->and($copy->id)->not->toBe($old->id)
            ->and(fn () => $this->worksheets->copyFromPreviousYear($this->owner, $this->company, 2026))->toThrow(ValidationException::class);
    });
});
