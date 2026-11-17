<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CostCenter;
use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Domain\CostCenterShares;
use App\Modules\Payroll\Domain\SalaryVoucher;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\Support\Reports\PayrollReports;
use App\Modules\Planning\Actions\AttestPeriod;
use App\Modules\Planning\Actions\CopyPeriod;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Actions\SendToPayroll;
use App\Modules\Planning\Livewire\SchedulePage;
use App\Modules\Planning\Livewire\SetupPage;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\AttestData;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Shifts on cost centres, carried to the payroll voucher (ADR-037, gap 7):
 * an area's default cost centre, the shift's own, time reports and payslip
 * lines per cost centre, and employer contributions split over the cost
 * centres in proportion to gross pay, to the öre, so the salary voucher
 * still balances.
 */

function p2At(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/Stockholm');
}

function p2Area(string $name, ?string $costCenter): Area
{
    return app(SaveArea::class)(test()->owner, test()->company, ['name' => $name, 'colour' => '#2e86ab', 'cost_center' => $costCenter]);
}

beforeEach(function () {
    $this->travelTo(p2At('2026-11-20 10:00:00'));
    Notification::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    $this->actingAs($this->owner);
    $this->kitchen = CostCenter::create(['code' => '10', 'name' => 'Kök']);
    $this->bar = CostCenter::create(['code' => '20', 'name' => 'Bar']);
    $this->office = CostCenter::create(['code' => '30', 'name' => 'Kontor']);
    $this->closed = CostCenter::create(['code' => '90', 'name' => 'Stängd', 'is_active' => false]);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD', 'start_date' => '2026-01-01', 'cost_center' => '30']);
});

describe('the largest-remainder split', function () {
    it('gives the left-over öre to the largest remainders, then the larger weight, then the first key', function () {
        expect(CostCenterShares::split(100, ['a' => 1, 'b' => 1, 'c' => 1]))->toBe(['a' => 34, 'b' => 33, 'c' => 33])
            ->and(CostCenterShares::split(10, ['a' => 1, 'b' => 2]))->toBe(['a' => 3, 'b' => 7])
            ->and(CostCenterShares::split(-10, ['a' => 1, 'b' => 2]))->toBe(['a' => -3, 'b' => -7])
            ->and(CostCenterShares::split(0, ['a' => 5, 'b' => 7]))->toBe(['a' => 0, 'b' => 0])
            ->and(CostCenterShares::split(1, ['a' => 1, 'b' => 3]))->toBe(['a' => 0, 'b' => 1])
            // Huge amounts and weights do not overflow.
            ->and(CostCenterShares::split(PHP_INT_MAX, ['a' => 1_000_000_000_000, 'b' => 1_000_000_000_000]))->toBe(['a' => intdiv(PHP_INT_MAX, 2) + 1, 'b' => intdiv(PHP_INT_MAX, 2)]);
    });

    it('always sums to the total, each part within one öre of its exact share, the same every time', function () {
        mt_srand(20261117);

        for ($i = 0; $i < 500; $i++) {
            $total = mt_rand(-10_000_000, 10_000_000);
            $weights = [];

            foreach (range(1, mt_rand(1, 6)) as $key) {
                $weights[$key] = mt_rand(1, 5_000_000);
            }

            $parts = CostCenterShares::split($total, $weights);
            $sum = array_sum($weights);

            expect(array_sum($parts))->toBe($total)
                ->and(CostCenterShares::split($total, $weights))->toBe($parts);

            foreach ($weights as $key => $weight) {
                expect(abs($parts[$key] - $total * $weight / $sum))->toBeLessThan(1.0);
            }
        }
    });

    it('refuses nothing to split over and weights that are not positive', function () {
        expect(fn () => CostCenterShares::split(10, []))->toThrow(InvalidArgumentException::class)
            ->and(fn () => CostCenterShares::split(10, ['a' => 0, 'b' => 1]))->toThrow(InvalidArgumentException::class);
    });

    it('splits a day\'s hours in hundredths, summing to exactly the hours one report would hold', function () {
        expect(SendToPayroll::hoursPerCostCenter(450, []))->toBe([[null, '7.50']])
            ->and(SendToPayroll::hoursPerCostCenter(450, ['' => 480]))->toBe([[null, '7.50']])
            ->and(SendToPayroll::hoursPerCostCenter(450, ['5' => 480, '' => 0]))->toBe([[5, '7.50']])
            ->and(SendToPayroll::hoursPerCostCenter(450, ['5' => 240, '7' => 240]))->toBe([[5, '3.75'], [7, '3.75']])
            // 100 min = 1,67 h = 167 hundredths: 55,67 and 111,33 → 56 and 111.
            ->and(SendToPayroll::hoursPerCostCenter(100, ['5' => 60, '7' => 120]))->toBe([[5, '0.56'], [7, '1.11']])
            // 1 min = 0,02 h: one hundredth each; a part of 0,00 h is left out.
            ->and(SendToPayroll::hoursPerCostCenter(1, ['5' => 60, '7' => 60]))->toBe([[5, '0.01'], [7, '0.01']])
            ->and(SendToPayroll::hoursPerCostCenter(1, ['5' => 10, '7' => 600]))->toBe([[7, '0.02']]);
    });
});

function p2Center(int $id): CostCenter
{
    $codes = [10 => 'K1', 20 => 'K2', 30 => 'K3'];

    return (new CostCenter(['code' => $codes[$id], 'name' => $codes[$id]]))->forceFill(['id' => $id]);
}

function p2Line(?int $costCenter, int $ore, int $payCode = 1, array $explanation = []): PayslipLine
{
    return (new PayslipLine)
        ->forceFill(['pay_code_id' => $payCode, 'amount_ore' => $ore, 'tax_effect' => 'TABLE', 'description' => 'Lön', 'explanation' => $explanation, 'cost_center_id' => $costCenter])
        ->setRelation('costCenter', $costCenter === null ? null : p2Center($costCenter));
}

/** @param  list<PayslipLine>  $lines */
function p2Payslip(array $lines, int $tax, int $net, int $contributions): Payslip
{
    return (new Payslip)->forceFill(['tax_ore' => $tax, 'net_ore' => $net, 'employer_contribution_ore' => $contributions])
        ->setRelation('lines', collect($lines))
        ->setRelation('employment', (new Employment)->forceFill(['cost_center_id' => 30])->setRelation('costCenter', p2Center(30))->setRelation('project', null));
}

/** @return array<int, PayCode> */
function p2PayCodes(): array
{
    return [
        1 => (new PayCode)->forceFill(['id' => 1, 'debit_account_id' => 100, 'name_sv' => 'Timlön', 'is_contribution_basis' => true]),
        // Tax-free allowance: a cost, no contributions; and a pay code that keeps off the employment's dimensions.
        2 => (new PayCode)->forceFill(['id' => 2, 'debit_account_id' => 101, 'name_sv' => 'Traktamente', 'is_contribution_basis' => false]),
        3 => (new PayCode)->forceFill(['id' => 3, 'debit_account_id' => 100, 'name_sv' => 'Bonus', 'is_contribution_basis' => true, 'use_employment_dimensions' => false]),
    ];
}

/** @return list<array{?string, int}> 7510 rows as [cost centre, amount] */
function p2Contributions(array $rows): array
{
    return array_values(array_map(fn (array $row): array => [$row['cost_center'] ?? null, $row['amount']], array_filter($rows, fn (array $row): bool => $row['account'] === SalaryVoucher::CONTRIBUTIONS_COST)));
}

it('books employer contributions per cost centre in proportion to the contribution basis, to the öre, and balances', function () {
    $accounts = collect([100 => (new Account)->forceFill(['id' => 100, 'number' => 7285]), 101 => (new Account)->forceFill(['id' => 101, 'number' => 7321])]);
    // 100 kr each on K1 and K2 from time reports, 100 kr on the employment's K3; 100,01 kr contributions.
    $payslip = p2Payslip([p2Line(10, 10_000), p2Line(20, 10_000), p2Line(null, 10_000)], 9_000, 21_000, 10_001);
    $rows = SalaryVoucher::rows(collect([$payslip]), collect(p2PayCodes()), $accounts);

    expect(p2Contributions($rows))->toBe([['K1', 3_334], ['K2', 3_334], ['K3', 3_333]])
        ->and(array_sum(array_column($rows, 'amount')))->toBe(0)
        ->and(array_map(fn (array $row): array => [$row['account'], $row['cost_center'] ?? null, $row['amount']], array_slice($rows, 0, 3)))->toBe([[7285, 'K1', 10_000], [7285, 'K2', 10_000], [7285, 'K3', 10_000]]);

    // A tax-free allowance on K3 bears no contributions; a fuel benefit counts at its market value (contribution_basis_ore).
    $mixed = p2Payslip([p2Line(10, 10_000), p2Line(null, 50_000, 2), p2Line(20, 12_000, 1, ['contribution_basis_ore' => 10_000])], 0, 72_000, 6_284);
    expect(CostCenterShares::contributions($mixed, collect(p2PayCodes()), CostCenterShares::resolver(30, true, collect(p2PayCodes())), 30))->toBe([10 => 3_142, 20 => 3_142]);

    // Without a positive basis anywhere (only a deduction) the contributions stay on the fallback cost centre.
    $deduction = p2Payslip([p2Line(null, -500)], 0, -500, -157);
    expect(CostCenterShares::contributions($deduction, collect(p2PayCodes()), CostCenterShares::resolver(30, true, collect(p2PayCodes())), 30))->toBe([30 => -157]);
});

it('takes the employment\'s cost centre only when the payroll setting and the pay code say so, and Planering\'s always', function () {
    $accounts = collect([100 => (new Account)->forceFill(['id' => 100, 'number' => 7285])]);
    // Timlön from Planering on K1, monthly pay without one, a bonus whose pay code keeps off the employment's dimensions.
    $payslip = p2Payslip([p2Line(10, 10_000), p2Line(null, 10_000), p2Line(null, 10_000, 3)], 9_000, 21_000, 9_000);
    $lines = fn (array $rows): array => array_values(array_map(fn (array $row): array => [$row['cost_center'] ?? null, $row['amount']], array_filter($rows, fn (array $row): bool => $row['account'] === 7285)));

    $on = SalaryVoucher::rows(collect([$payslip]), collect(p2PayCodes()), $accounts);
    $off = SalaryVoucher::rows(collect([$payslip]), collect(p2PayCodes()), $accounts, false);

    expect($lines($on))->toBe([['K1', 10_000], ['K3', 10_000], [null, 10_000]])
        ->and(p2Contributions($on))->toBe([['K1', 3_000], ['K3', 3_000], [null, 3_000]])
        ->and($lines($off))->toBe([['K1', 10_000], [null, 20_000]])
        ->and(p2Contributions($off))->toBe([['K1', 3_000], [null, 6_000]])
        ->and(array_sum(array_column($on, 'amount')))->toBe(0)
        ->and(array_sum(array_column($off, 'amount')))->toBe(0);
});

it('gives an area a cost centre by code, refusing unknown, inactive and other companies\' codes', function () {
    $kitchen = p2Area('Kök', '10');

    expect($kitchen->cost_center_id)->toBe($this->kitchen->id)
        ->and(fn () => p2Area('Bar', '99'))->toThrow(ValidationException::class, 'Kostnadsstället 99 finns inte.')
        ->and(fn () => p2Area('Bar', '90'))->toThrow(ValidationException::class, 'Kostnadsstället 90 är inaktivt.');

    // Another company's cost centre is not found.
    [, $other] = Bookkeeping::company();
    CurrentCompany::run($other, fn () => CostCenter::create(['code' => '77', 'name' => 'Annat']));
    CurrentCompany::set($this->company);
    expect(fn () => p2Area('Bar', '77'))->toThrow(ValidationException::class, 'Kostnadsstället 77 finns inte.');

    // Left out of the input the area keeps it; a cost centre made inactive later may stay.
    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Köket', 'colour' => '#2e86ab'], $kitchen);
    expect($kitchen->cost_center_id)->toBe($this->kitchen->id);

    $this->kitchen->forceFill(['is_active' => false])->save();
    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#2e86ab', 'cost_center' => '10'], $kitchen);
    expect($kitchen->cost_center_id)->toBe($this->kitchen->id)
        ->and(AuditLog::query()->where('action', 'planning.area_created')->latest('id')->first()?->payload['after'])->toHaveKey('cost_center_id');
});

it('puts a shift on its area\'s cost centre, lets it be changed, and refuses an inactive one for a new shift', function () {
    $kitchen = p2Area('Kök', '10');
    $bar = p2Area('Bar', '20');
    $save = app(SaveShift::class);
    $base = ['employee_id' => $this->anna->id, 'date' => '2026-11-23', 'start' => '08:00', 'end' => '16:00'];

    $shift = $save($this->owner, $this->company, [...$base, 'area_id' => $kitchen->id]);
    expect($shift->cost_center_id)->toBe($this->kitchen->id);

    $own = $save($this->owner, $this->company, [...$base, 'area_id' => $kitchen->id, 'cost_center' => '20']);
    expect($own->cost_center_id)->toBe($this->bar->id);

    // Empty: the area's again. Moved to another area without a choice: that area's.
    expect($save($this->owner, $this->company, [...$base, 'area_id' => $kitchen->id, 'cost_center' => ''], $own)->cost_center_id)->toBe($this->kitchen->id)
        ->and($save($this->owner, $this->company, [...$base, 'area_id' => $bar->id], $shift)->cost_center_id)->toBe($this->bar->id)
        // No area, no choice: the employment's (null).
        ->and($save($this->owner, $this->company, $base)->cost_center_id)->toBeNull()
        ->and(fn () => $save($this->owner, $this->company, [...$base, 'cost_center' => '90']))->toThrow(ValidationException::class, 'Kostnadsstället 90 är inaktivt.');

    // A cost centre made inactive: shifts on it keep it when changed, but a new shift in the area gets none.
    $this->bar->forceFill(['is_active' => false])->save();
    $moved = $save($this->owner, $this->company, [...$base, 'start' => '09:00', 'area_id' => $bar->id], $shift->fresh());
    expect($moved->cost_center_id)->toBe($this->bar->id)
        ->and($save($this->owner, $this->company, [...$base, 'area_id' => $bar->id])->cost_center_id)->toBeNull();
});

it('keeps a published shift published when only its cost centre changes, and audits the change', function () {
    $kitchen = p2Area('Kök', '10');
    $shift = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => '2026-11-23', 'start' => '08:00', 'end' => '16:00']);
    app(PublishPeriod::class)($this->owner, $this->company, p2At('2026-11-23'), p2At('2026-11-29'));

    $changed = app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => '2026-11-23', 'start' => '08:00', 'end' => '16:00', 'cost_center' => '20'], $shift->fresh());
    $audit = AuditLog::query()->where('action', 'planning.shift_updated')->latest('id')->firstOrFail();

    expect($changed->status)->toBe(Shift::PUBLISHED)
        ->and($changed->cost_center_id)->toBe($this->bar->id)
        ->and($audit->payload)->toEqual(['before' => ['cost_center_id' => $this->kitchen->id], 'after' => ['cost_center_id' => $this->bar->id]]);
});

it('copies a period with each shift\'s cost centre', function () {
    $kitchen = p2Area('Kök', '10');
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => '2026-11-23', 'start' => '08:00', 'end' => '16:00', 'cost_center' => '20']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => '2026-11-24', 'start' => '08:00', 'end' => '16:00']);

    app(CopyPeriod::class)($this->owner, $this->company, p2At('2026-11-23'), 1, p2At('2026-11-30'));

    expect(Shift::query()->where('source', 'COPY')->orderBy('starts_at')->pluck('cost_center_id')->all())->toBe([$this->bar->id, $this->kitchen->id]);
});

it('carries shifts on cost centres through attest, payroll and the salary voucher, balanced to the öre', function () {
    $kitchen = p2Area('Kök', '10');
    $bar = p2Area('Bar', '20');
    // Mon: 4 h in the kitchen and 4 h in the bar. Tue: 6 h in the kitchen. Wed: 3 h without an area (the employment's 30).
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => '2026-11-09', 'start' => '08:00', 'end' => '12:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $bar->id, 'date' => '2026-11-09', 'start' => '13:00', 'end' => '17:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '14:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-11', 'start' => '08:00', 'end' => '11:00']);
    app(PublishPeriod::class)($this->owner, $this->company, p2At('2026-11-09'), p2At('2026-11-15'));
    app(AttestPeriod::class)($this->owner, $this->company, p2At('2026-11-01'), p2At('2026-11-30'));
    app(SendToPayroll::class)($this->owner, $this->company, p2At('2026-11-01'), p2At('2026-11-30'));

    $reports = TimeReport::query()->orderBy('date')->orderBy('cost_center_id')->get()
        ->map(fn (TimeReport $report): array => [$report->date->toDateString(), $report->cost_center_id, $report->hours])->all();

    expect($reports)->toBe([
        ['2026-11-09', $this->kitchen->id, '4.00'],
        ['2026-11-09', $this->bar->id, '4.00'],
        ['2026-11-10', $this->kitchen->id, '6.00'],
        ['2026-11-11', null, '3.00'],
    ]);

    $runs = app(PayrollRuns::class);
    $run = $runs->create($this->owner, $this->company, '2026-11');
    $runs->calculate($this->owner, $run);
    $payslip = Payslip::query()->with('lines')->sole();
    $hourly = $payslip->lines->filter(fn (PayslipLine $line): bool => $line->explanation['source'] === 'time_report')
        ->map(fn (PayslipLine $line): array => [$line->cost_center_id, $line->quantity, $line->amount_ore->ore, $line->description])->values()->all();

    // 150 kr an hour: 3 h on the employment's cost centre, 10 h in the kitchen, 4 h in the bar.
    expect($hourly)->toBe([
        [null, '3.000', 45_000, 'Timlön enligt tidrapport'],
        [$this->kitchen->id, '10.000', 150_000, 'Timlön enligt tidrapport, kostnadsställe 10'],
        [$this->bar->id, '4.000', 60_000, 'Timlön enligt tidrapport, kostnadsställe 20'],
    ]);

    $admin = Bookkeeping::member($this->company, 'admin');
    $runs->approve($admin, $run->fresh());
    $runs->markPaid($this->owner, $run->fresh());
    app(AddPayrollAccounts::class)($this->owner, $this->company);
    $voucher = $runs->post($this->owner, $run->fresh())->voucher->load(['rows.account', 'rows.costCenter']);

    $by = [];

    foreach ($voucher->rows as $row) {
        $key = $row->account->number.'|'.($row->costCenter?->code ?? '');
        $by[$key] = ($by[$key] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
    }

    $contributions = $payslip->employer_contribution_ore->ore;
    $contributionRows = array_filter($by, fn (string $key): bool => str_starts_with($key, '7510|'), ARRAY_FILTER_USE_KEY);
    ksort($contributionRows);
    $gross = $payslip->gross_ore->ore;
    $employmentGross = $gross - 150_000 - 60_000;

    expect(array_sum($by))->toBe(0)
        ->and(array_sum($contributionRows))->toBe($contributions)
        ->and(array_keys($contributionRows))->toBe(['7510|10', '7510|20', '7510|30'])
        // Each part within one öre of its exact share of the gross pay.
        ->and(abs($contributionRows['7510|10'] - $contributions * 150_000 / $gross))->toBeLessThan(1.0)
        ->and(abs($contributionRows['7510|20'] - $contributions * 60_000 / $gross))->toBeLessThan(1.0)
        ->and(abs($contributionRows['7510|30'] - $contributions * $employmentGross / $gross))->toBeLessThan(1.0);

    // Kostnad per kostnadsställe shows what the voucher books.
    $report = collect(app(PayrollReports::class)->costCenters('2026-11', '2026-11'))->keyBy('code');

    expect($report['10']['gross_ore'])->toBe(150_000)
        ->and($report['20']['gross_ore'])->toBe(60_000)
        ->and($report['30']['gross_ore'])->toBe($employmentGross)
        ->and($report->sum('contributions_ore'))->toBe($contributions)
        ->and($report['10']['contributions_ore'])->toBe($contributionRows['7510|10']);
});

it('sets the cost centre from Inställningar and the shift editor, with a toast', function () {
    Livewire::test(SetupPage::class)
        ->call('createArea')
        ->set('form.name', 'Kök')
        ->set('form.cost_center', '10')
        ->call('saveArea')
        ->assertDispatched('toast', message: 'Avdelningen är tillagd.')
        ->assertSee('Kostnadsställe 10 Kök');

    $kitchen = Area::query()->sole();

    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->call('create', $this->anna->id, '2026-11-23')
        ->assertSee('Kostnadsställe')
        ->set('form.area_id', $kitchen->id)
        ->set('form.cost_center', '20')
        ->call('save')
        ->assertDispatched('toast', message: 'Passet är tillagt som utkast.');

    $shift = Shift::query()->sole();
    expect($shift->cost_center_id)->toBe($this->bar->id);

    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->call('edit', $shift->id)
        ->assertSet('form.cost_center', '20')
        ->set('form.cost_center', '90')
        ->call('save')
        ->assertDispatched('toast', message: 'Kostnadsstället 90 är inaktivt. Välj ett aktivt kostnadsställe.');
});

it('loads Inställningar in the same number of queries for 2 areas on cost centres and for 8', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(SetupPage::class);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    p2Area('Kök', '10');
    p2Area('Bar', '20');
    $count();
    $short = $count();

    foreach (range(1, 6) as $i) {
        p2Area("Avdelning {$i}", $i % 2 === 0 ? '10' : '30');
    }

    expect($count())->toBe($short);
});

it('reads the day\'s cost centres for sending in one query, whatever the number of days', function () {
    $kitchen = p2Area('Kök', '10');
    $count = function (int $days) use ($kitchen): int {
        foreach (range(0, $days - 1) as $day) {
            Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => p2At('2026-11-02')->addDays($day)->toDateString(), 'start' => '08:00', 'end' => '12:00']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        (fn () => $this->costCenterShifts(p2At('2026-11-01'), p2At('2026-11-30'), [test()->anna->id]))->call(app(SendToPayroll::class));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count(2))->toBe(1)
        ->and($count(10))->toBe(1);
});

it('keeps a shift on its area\'s cost centre when that becomes inactive and the shift is edited', function () {
    $kitchen = p2Area('Kök', '10');
    $shift = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => '2026-11-23', 'start' => '08:00', 'end' => '16:00']);
    $this->kitchen->forceFill(['is_active' => false])->save();

    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->call('edit', $shift->id)
        ->assertSet('form.cost_center', '')
        ->set('form.start', '09:00')
        ->call('save')
        ->assertDispatched('toast');

    expect($shift->fresh()->cost_center_id)->toBe($this->kitchen->id)
        ->and($shift->fresh()->starts_at->format('H:i'))->toBe('09:00');
});

it('puts each OB code on the cost centre whose shift gave it, and hourly pay by planned minutes', function () {
    $setup = app(AgreementSetup::class);
    app(ModuleManager::class)->enable(AgreementsModule::KEY, $this->company, $this->owner);
    $binding = $setup->saveBinding($this->owner, $this->company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);
    $setup->saveEmployment($this->owner, $this->company, Employment::query()->where('employee_id', $this->anna->id)->sole(), ['collective_agreement_id' => $binding->id, 'agreement_group' => '2']);
    AgreementRuleVersion::query()->where('agreement_key', 'visita-hrf-grona-riks')->where('variant', 'MEMBER')->sole()->forceFill(['verified_by' => 'Granskare', 'verified_at' => now()])->save();
    $kitchen = p2Area('Kök', '10');
    $bar = p2Area('Bar', '20');

    // Tuesday: 08–14 in the kitchen, 17–23 in the bar. OB (from 20:00 on a weekday) arises only in the bar.
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '14:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $bar->id, 'date' => '2026-11-10', 'start' => '17:00', 'end' => '23:00']);
    app(PublishPeriod::class)($this->owner, $this->company, p2At('2026-11-09'), p2At('2026-11-15'));
    app(AttestPeriod::class)($this->owner, $this->company, p2At('2026-11-01'), p2At('2026-11-30'));
    app(SendToPayroll::class)($this->owner, $this->company, p2At('2026-11-01'), p2At('2026-11-30'));

    $codes = PayCode::query()->pluck('code', 'id');
    $reports = TimeReport::query()->orderBy('pay_code_id')->orderBy('cost_center_id')->get()
        ->map(fn (TimeReport $report): array => [$codes[$report->pay_code_id], $report->cost_center_id, $report->hours])->sortBy(fn (array $r): string => $r[0].$r[1])->values()->all();

    expect($reports)->toBe([
        ['GR_OB', $this->bar->id, '3.00'],
        ['TIMLON', $this->kitchen->id, '6.00'],
        ['TIMLON', $this->bar->id, '6.00'],
    ]);
});

it('weighs Timlön and the day\'s overtime by planned minutes and OB by where it arose', function () {
    $shifts = [
        ['cost_center' => '10', 'start' => p2At('2026-11-10 08:00'), 'end' => p2At('2026-11-10 12:00'), 'minutes' => 240],
        ['cost_center' => '20', 'start' => p2At('2026-11-10 13:00'), 'end' => p2At('2026-11-10 15:00'), 'minutes' => 120],
    ];
    $noAgreement = fn () => null;

    expect(SendToPayroll::weights('TIMLON', $shifts, [], $noAgreement))->toBe(['10' => 240, '20' => 120])
        // Without the agreement to tell where OB arose, the planned minutes.
        ->and(SendToPayroll::weights('GR_OB', $shifts, ['GR_OB'], $noAgreement))->toBe(['10' => 240, '20' => 120])
        ->and(SendToPayroll::weights('OVERTID', $shifts, ['GR_OB'], $noAgreement))->toBe(['10' => 240, '20' => 120])
        ->and(SendToPayroll::weights('OVERTID', [$shifts[0]], [], $noAgreement))->toBe(['10' => 240])
        ->and(SendToPayroll::weights('OVERTID', [], [], $noAgreement))->toBe([]);
});

it('shows a published shift changed back to draft with its published time on Tid att attestera', function () {
    $shift = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00', 'break_minutes' => 30]);
    app(PublishPeriod::class)($this->owner, $this->company, p2At('2026-11-09'), p2At('2026-11-15'));
    $edited = app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '10:00', 'end' => '16:00', 'break_minutes' => 30], $shift->fresh());

    $row = collect(app(AttestData::class)->load($this->company, p2At('2026-11-10'), p2At('2026-11-10'))['rows'])->sole();

    expect($edited->status)->toBe(Shift::DRAFT)
        ->and($row->comparison->plannedMinutes())->toBe(450)
        ->and($row->comparison->planned[0]->start?->format('H:i'))->toBe('08:00');
});

it('books a day\'s overtime where the day\'s shift was, also for an employment without a cost centre', function () {
    $bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['start_date' => '2026-01-01']);
    $kitchen = p2Area('Kök', '10');
    Planning::shift($this->owner, $this->company, ['employee_id' => $bertil->id, 'area_id' => $kitchen->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    app(PublishPeriod::class)($this->owner, $this->company, p2At('2026-11-09'), p2At('2026-11-15'));
    app(AttestPeriod::class)($this->owner, $this->company, p2At('2026-11-01'), p2At('2026-11-30'));
    // The day's overtime as the DAILY method writes it into the day's basis.
    Attestation::query()->where('employee_id', $bertil->id)->sole()->forceFill(['basis' => ['OVERTID' => 90]])->save();

    app(SendToPayroll::class)($this->owner, $this->company, p2At('2026-11-01'), p2At('2026-11-30'));
    $report = TimeReport::query()->where('employee_id', $bertil->id)->sole();

    expect(Employment::query()->where('employee_id', $bertil->id)->sole()->cost_center_id)->toBeNull()
        ->and(PayCode::query()->whereKey($report->pay_code_id)->value('code'))->toBe('OVERTID')
        ->and($report->cost_center_id)->toBe($this->kitchen->id)
        ->and($report->hours)->toBe('1.50');
});
