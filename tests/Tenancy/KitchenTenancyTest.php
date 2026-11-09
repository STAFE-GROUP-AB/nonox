<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Kitchen\Actions\ConsumeSales;
use App\Modules\Kitchen\Actions\MealPayroll;
use App\Modules\Kitchen\Actions\RegisterStaffMeal;
use App\Modules\Kitchen\Actions\RegisterWaste;
use App\Modules\Kitchen\Actions\SaveRecipe;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\Livewire\StaffMealsPage;
use App\Modules\Kitchen\Livewire\VariancePage;
use App\Modules\Kitchen\Livewire\WastePage;
use App\Modules\Kitchen\Models\ConsumptionRun;
use App\Modules\Kitchen\Models\ConsumptionWait;
use App\Modules\Kitchen\Models\MealPayrollProposal;
use App\Modules\Kitchen\Models\SaleConsumption;
use App\Modules\Kitchen\Models\StaffMeal;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\Kitchen\Models\WasteMeasurement;
use App\Modules\Kitchen\Support\TheoreticalConsumption;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Kitchen;
use Tests\Support\Payroll;

/*
 * AGENTS.md §13.2 for Kök and the structure engine (ADR-073 §13): company A
 * never sees B's recipes, rows, unit conversions, waste, plate waste, staff
 * meals, payroll proposals or till consumption, through Eloquent, the
 * engine, the pages or the reports; B's ids answer 404.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));

    foreach (['a', 'b'] as $side) {
        ['owner' => $owner, 'company' => $company, 'places' => $places] = Kitchen::company();
        $this->actingAs($owner);
        $fx = Kitchen::fixtures($company, $owner, $places['bin1'], '20');
        ArticleUnitConversion::create(['article_id' => $fx['articles']['gradde']->id, 'unit' => 'fl', 'factor_milli' => 500]);
        $waste = app(RegisterWaste::class)($owner, $company, ['kind' => 'INGREDIENT', 'article_id' => $fx['articles']['lok']->id, 'quantity' => '1', 'location_id' => $places['bin1']->id, 'reason' => 'FEL']);
        app(RegisterWaste::class)->plate($owner, $company, '1');
        // Tax tables and rules are shared by the installation: imported once, the module on for both.
        $side === 'a' ? Payroll::setUp($company, $owner) : app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
        $employee = Payroll::employee($owner, $company);
        $meal = app(RegisterStaffMeal::class)($owner, $company, ['date' => '2026-11-03', 'meal' => 'LUNCH', 'article_id' => $fx['articles']['dish']->id, 'reason' => 'PERSONALMAT', 'employee_id' => $employee->id, 'location_id' => $places['bin1']->id]);
        app(MealPayroll::class)->propose($owner, $company, '2026-11');
        $till = Kitchen::till($company, $owner, $places['bin1']);
        Kitchen::sale($till, '2026-11-05', [[$fx['articles']['dish'], 300_000]]);
        app(ModuleManager::class)->updateSettings(KitchenModule::KEY, $company, $owner, ['draw_on_sale' => true, 'draw_on_sale_from' => '2026-11-01']);
        app(ConsumeSales::class)($company);
        $this->{$side} = compact('owner', 'company', 'places', 'fx', 'waste', 'meal', 'employee');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes every Kök and structure model to the current company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([
    BillOfMaterials::class, BomRow::class, ArticleUnitConversion::class, WasteEntry::class, WasteMeasurement::class,
    StaffMeal::class, MealPayrollProposal::class, ConsumptionRun::class, SaleConsumption::class, ConsumptionWait::class,
]);

it('answers 404 for the other company\'s records', function () {
    $this->get(route('kitchen.recipes.show', $this->b['fx']['articles']['dish']->id))->assertNotFound();
    $this->get(route('kitchen.recipes.show', $this->a['fx']['articles']['dish']->id))->assertOk();

    Livewire::test(WastePage::class)->call('correct', $this->b['waste']->id)->assertNotFound();
    Livewire::test(StaffMealsPage::class)->call('correct', $this->b['meal']->id)->assertNotFound();
    Livewire::test(VariancePage::class)->call('resolve', 999_999, false)->assertNotFound();

    expect(WasteEntry::query()->find($this->b['waste']->id))->toBeNull()
        ->and(StaffMeal::query()->find($this->b['meal']->id))->toBeNull();
});

it('keeps the engine and the reports inside the company', function () {
    $book = StructureBook::for($this->a['company']);
    expect($book->outputs())->not->toContain($this->b['fx']['articles']['dish']->id)
        ->and($book->validOn($this->b['fx']['articles']['dish']->id, CarbonImmutable::today()))->toBeNull();

    $theory = TheoreticalConsumption::between($this->a['company'], CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    expect(array_keys($theory->sold))->toBe([$this->a['fx']['articles']['dish']->id]);
});

it('refuses a recipe row with another company\'s article', function () {
    expect(fn () => app(SaveRecipe::class)($this->a['owner'], $this->a['company'], $this->a['fx']['articles']['dish'], [
        'name' => 'X', 'yield' => '1', 'yield_unit' => 'PORTIONS', 'rows' => [['article_id' => $this->b['fx']['articles']['lok']->id, 'quantity' => '1']],
    ]))->toThrow(ValidationException::class, 'finns inte');
});
