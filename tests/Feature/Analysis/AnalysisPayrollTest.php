<?php

declare(strict_types=1);

use App\Domain\Analysis\DatasetCatalogue;
use App\Domain\Analysis\Datasets\PayrollDataset;
use App\Livewire\Analysis\AnalysisPage;
use App\Models\CostCenter;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\PayslipLine;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * ADR-081 gap 7, E17, owner decision 17: aggregated pay per cost centre and
 * pay code, never per person. A cell with fewer than three people is hidden,
 * and so is every total and regrouping that contains one, so no choice of
 * rows, columns or filters shows a small group's pay by subtracting one
 * figure from another.
 */
beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->kitchen = CostCenter::create(['code' => '10', 'name' => 'Kök']);
    $this->bar = CostCenter::create(['code' => '20', 'name' => 'Bar']);

    foreach ([1, 2, 3] as $i) {
        Payroll::employee($this->owner, $this->company, ['first_name' => "Kock{$i}", 'personal_number' => '1980010112'.$i.Luhn::checkDigit("80010112{$i}"), 'cost_center' => '10', 'salary' => '30 000']);
    }

    Payroll::employee($this->owner, $this->company, ['first_name' => 'Bartender', 'personal_number' => '19800101124'.Luhn::checkDigit('800101124'), 'cost_center' => '20', 'salary' => '45 000']);
});

function e17Approve($test, string $period): void
{
    $runs = app(PayrollRuns::class);
    $run = $runs->create($test->owner, $test->company, $period);
    $runs->calculate($test->owner, $run);
    $runs->approve(Bookkeeping::member($test->company, 'admin'), $run->fresh());
}

function e17Payroll(array $set = [])
{
    $page = Livewire::test(AnalysisPage::class)->set('source', 'payroll')->set('from', '2026-01')->set('to', '2026-12');

    foreach ($set as $property => $value) {
        $page->set($property, $value);
    }

    return $page;
}

it('offers the payroll dataset to those with payroll:view and shows pay per cost centre', function () {
    e17Approve($this, '2026-04');

    expect(array_keys(DatasetCatalogue::for($this->owner, $this->company)))->toContain('payroll');

    $result = e17Payroll(['rows' => ['cost_center']])->viewData('result');
    $kitchen = collect($result->rows)->firstWhere('label', '10 Kök');
    $bar = collect($result->rows)->firstWhere('label', '20 Bar');

    expect($result->cells[$kitchen['key']]['']['hidden'])->toBeFalse()
        ->and($result->cells[$kitchen['key']]['']['v'])->toBeGreaterThan(0)
        ->and($result->cells[$bar['key']]['']['hidden'])->toBeTrue()
        ->and($result->cells[$bar['key']]['']['v'])->toBe(0);
});

it('hides a total and a regrouping that contains a group of fewer than three people', function () {
    e17Approve($this, '2026-04');

    $page = e17Payroll(['rows' => ['pay_code']]);
    $result = $page->viewData('result');

    // The bar's pay code cell shares a pay code with the kitchen, so its figure would show the bar's pay by subtraction.
    expect(collect($result->rows)->every(fn (array $row): bool => $result->cells[$row['key']]['']['hidden']))->toBeTrue()
        ->and($result->grandTotal['hidden'])->toBeTrue();

    $page->assertSee('Dold')->assertSee('Lön visas bara sammanräknad');
});

it('shows the kitchen alone when the filter leaves out the small group, and no filter reveals the bar', function () {
    e17Approve($this, '2026-04');

    $filtered = e17Payroll(['rows' => ['pay_code'], 'filterDimension1' => 'cost_center', 'filterValues1' => [(string) $this->kitchen->id]])->viewData('result');
    $barOnly = e17Payroll(['rows' => ['pay_code'], 'filterDimension1' => 'cost_center', 'filterValues1' => [(string) $this->bar->id]])->viewData('result');
    $byMonth = e17Payroll(['rows' => ['period'], 'filterDimension1' => 'cost_center', 'filterValues1' => [(string) $this->bar->id]])->viewData('result');

    expect($filtered->grandTotal['hidden'])->toBeFalse()
        ->and($filtered->grandTotal['v'])->toBeGreaterThan(0)
        ->and($barOnly->grandTotal['hidden'])->toBeTrue()
        ->and($barOnly->grandTotal['v'])->toBe(0)
        ->and($byMonth->grandTotal['hidden'])->toBeTrue();
});

it('never shows a person: no per-person dimension exists and the page holds no name', function () {
    e17Approve($this, '2026-04');

    $dataset = collect(DatasetCatalogue::all())->firstWhere(fn ($dataset): bool => $dataset->key() === 'payroll');

    expect(array_keys($dataset->dimensions()))->toBe(['pay_code', 'cost_center', 'period'])
        ->and($dataset->drillable())->toBeFalse();

    e17Payroll(['rows' => ['cost_center'], 'column' => 'pay_code'])->assertDontSee('Kock1')->assertDontSee('Bartender');
});

it('is not offered to a member without payroll:view', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(array_keys(DatasetCatalogue::for($bookkeeper, $this->company)))->not->toContain('payroll');
});

it('keeps the number written in the SQL equal to the rule', function () {
    expect((int) PayrollDataset::MIN_PEOPLE_SQL)->toBe(PayrollDataset::MIN_PEOPLE)
        ->and(PayrollDataset::MIN_PEOPLE)->toBe(3)
        ->and((int) PayrollDataset::DOMINANCE_PERCENT_SQL)->toBe(PayrollDataset::DOMINANCE_PERCENT);
});

it('does not count a person with 0 kr: two paid people and one at zero in a cell give Dold', function () {
    CostCenter::create(['code' => '30', 'name' => 'Lager']);

    foreach ([[7, '20 000'], [8, '20 000'], [9, '0']] as [$i, $salary]) {
        Payroll::employee($this->owner, $this->company, ['first_name' => "Lager{$i}", 'personal_number' => '1980010112'.$i.Luhn::checkDigit("80010112{$i}"), 'cost_center' => '30', 'salary' => $salary]);
    }

    e17Approve($this, '2026-04');

    $result = e17Payroll(['rows' => ['cost_center']])->viewData('result');
    $warehouse = collect($result->rows)->firstWhere('label', '30 Lager');

    // The person at 0 kr has a payslip line with amount 0 on the same cost centre, and is not counted.
    $zeroOnWarehouse = PayslipLine::query()->toBase()
        ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
        ->join('employees', 'employees.id', '=', 'payslips.employee_id')
        ->join('employments', 'employments.id', '=', 'payslips.employment_id')
        ->join('cost_centers', 'cost_centers.id', '=', DB::raw('COALESCE(payslip_lines.cost_center_id, employments.cost_center_id)'))
        ->where('employees.first_name', 'Lager9')
        ->where('cost_centers.code', '30')
        ->where('payslip_lines.amount_ore', 0)
        ->count();

    expect($zeroOnWarehouse)->toBeGreaterThan(0)
        ->and($result->cells[$warehouse['key']]['']['hidden'])->toBeTrue()
        ->and($result->cells[$warehouse['key']]['']['v'])->toBe(0);
});

it('shows the same cell when all three people have pay', function () {
    CostCenter::create(['code' => '30', 'name' => 'Lager']);

    foreach ([[7, '20 000'], [8, '20 000'], [9, '20 000']] as [$i, $salary]) {
        Payroll::employee($this->owner, $this->company, ['first_name' => "Lager{$i}", 'personal_number' => '1980010112'.$i.Luhn::checkDigit("80010112{$i}"), 'cost_center' => '30', 'salary' => $salary]);
    }

    e17Approve($this, '2026-04');

    $result = e17Payroll(['rows' => ['cost_center']])->viewData('result');
    $warehouse = collect($result->rows)->firstWhere('label', '30 Lager');

    expect($result->cells[$warehouse['key']]['']['hidden'])->toBeFalse()
        ->and($result->cells[$warehouse['key']]['']['v'])->toBeGreaterThan(0);
});

it('hides a cell of three people where one person accounts for more than 80 % of it', function () {
    foreach ([5, 6] as $i) {
        Payroll::employee($this->owner, $this->company, ['first_name' => "Extra{$i}", 'personal_number' => '1980010112'.$i.Luhn::checkDigit("80010112{$i}"), 'cost_center' => '20', 'salary' => '1 000']);
    }

    e17Approve($this, '2026-04');

    $result = e17Payroll(['rows' => ['cost_center']])->viewData('result');
    $kitchen = collect($result->rows)->firstWhere('label', '10 Kök');
    $bar = collect($result->rows)->firstWhere('label', '20 Bar');

    // Three people each, but about 45 000 of 47 000 is one person's in the bar.
    expect($result->cells[$kitchen['key']]['']['hidden'])->toBeFalse()
        ->and($result->cells[$bar['key']]['']['hidden'])->toBeTrue()
        ->and($result->grandTotal['hidden'])->toBeTrue();
});
