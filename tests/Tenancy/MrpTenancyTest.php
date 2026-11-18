<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\DemandForecast;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Manufacturing\Mrp\Actions\ConvertPlannedOrders;
use App\Modules\Manufacturing\Mrp\Actions\RunMrp;
use App\Modules\Manufacturing\Mrp\Livewire\MrpPage;
use App\Modules\Manufacturing\Mrp\Models\MrpPlannedOrder;
use App\Modules\Manufacturing\Mrp\Models\MrpRun;
use App\Modules\Manufacturing\Mrp\MrpSnapshot;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * AGENTS.md §13.2 for behovsplaneringen (ADR-081 gap 5, E16): two companies
 * use the same article number; a run in company A reads only A's articles,
 * forecast, stock and orders, shows only A's plan, and B's planned orders
 * answer 404 when A tries to convert them.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));

    foreach (['a' => ['Alfa', '4'], 'b' => ['Beta', '40']] as $side => [$name, $forecast]) {
        [$owner, $company] = Bookkeeping::company();
        $this->actingAs($owner);
        Inventory::enable($company, $owner);
        $supplier = Purchasing::supplier($owner, $company, "{$name}grossisten AB");
        $article = Inventory::article($company, $owner, 'SAMMA-1', "{$name}kopp", ['planning_method' => 'MRP', 'lead_time_days' => '7', 'default_supplier_id' => $supplier->id]);
        DemandForecast::query()->create(['article_id' => $article->id, 'period_kind' => 'WEEK', 'period_start' => '2026-11-30', 'quantity_milli' => (int) $forecast * 1000, 'source' => 'MANUAL']);
        $run = app(RunMrp::class)($owner, $company);
        $this->{$side} = compact('owner', 'company', 'article', 'run');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('reads and plans only the current company\'s articles', function () {
    $input = app(MrpSnapshot::class)->read($this->a['company'], CarbonImmutable::today(), 13);

    expect(array_keys($input->articles))->toBe([$this->a['article']->id])
        ->and(array_keys($input->forecast))->toBe([$this->a['article']->id])
        ->and(MrpPlannedOrder::query()->pluck('quantity_milli')->all())->toBe([4000])
        ->and(MrpRun::query()->pluck('id')->all())->toBe([$this->a['run']->id])
        ->and(CompanyScope::bypass(fn () => MrpPlannedOrder::query()->where('company_id', $this->b['company']->id)->pluck('quantity_milli')->all()))->toBe([40_000]);
});

it('shows only the current company\'s plan on the page', function () {
    Livewire::test(MrpPage::class)
        ->assertSee('Alfakopp')
        ->assertDontSee('Betakopp')
        ->assertDontSee('Betagrossisten AB');
});

it('answers 404 for another company\'s planned order and converts nothing', function () {
    $foreign = CompanyScope::bypass(fn () => MrpPlannedOrder::query()->where('company_id', $this->b['company']->id)->value('id'));

    expect(fn () => app(ConvertPlannedOrders::class)($this->a['owner'], $this->a['company'], [$foreign]))->toThrow(ModelNotFoundException::class)
        ->and(CompanyScope::bypass(fn () => PurchaseOrder::query()->count()))->toBe(0)
        ->and(CompanyScope::bypass(fn () => MrpPlannedOrder::query()->whereNotNull('converted_type')->count()))->toBe(0);
});

it('converts the own planned order into the own company only', function () {
    $own = MrpPlannedOrder::query()->value('id');

    app(ConvertPlannedOrders::class)($this->a['owner'], $this->a['company'], [$own]);

    expect(CompanyScope::bypass(fn () => PurchaseOrder::query()->pluck('company_id')->all()))->toBe([$this->a['company']->id]);
});

it('keeps each company\'s latest run its own', function () {
    // B runs again; A's latest run is still A's, so A's planned order converts.
    CurrentCompany::set($this->b['company']);
    app(RunMrp::class)($this->b['owner'], $this->b['company']);
    CurrentCompany::set($this->a['company']);

    $own = MrpPlannedOrder::query()->value('id');

    expect(app(ConvertPlannedOrders::class)($this->a['owner'], $this->a['company'], [$own])['purchase_orders'])->toHaveCount(1);
});
