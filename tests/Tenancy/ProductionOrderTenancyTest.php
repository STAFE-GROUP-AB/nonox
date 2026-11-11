<?php

declare(strict_types=1);

use App\Models\SalesDocument;
use App\Modules\Inventory\Purchasing\PurchaseProposal;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Livewire\Production\ProductionOrderPage;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Manufacturing\Models\ProductionOrderIssue;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Production\WorkInProgress;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Manufacturing;

/*
 * AGENTS.md §13.2 for production orders (ADR-071 §8): company A never sees
 * B's orders, their copies, issues or labour, through Eloquent, the pages,
 * the actions, the purchase proposal or the work in progress; B's ids
 * answer 404, and B's articles and customer orders cannot be used by A.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));

    foreach (['a', 'b'] as $side) {
        ['owner' => $owner, 'company' => $company, 'places' => $places] = Manufacturing::company();
        $this->actingAs($owner);
        $fx = Manufacturing::fixtures($company, $owner);
        Manufacturing::stock($company, $owner, $fx, $places['bin1']);
        $order = app(SaveProductionOrder::class)->create($owner, $company, ['article_id' => $fx['articles']['table']->id, 'quantity' => '2']);
        $life = app(ProductionOrderLifecycle::class);
        $life->release($owner, $company, $order);
        $life->start($owner, $company, $order, null, true);
        $sales = Manufacturing::document($company, $owner, [['BORD-180', '1', '12000,00', 'Ekbord 180']], SalesDocument::ORDER);
        $this->{$side} = compact('owner', 'company', 'fx', 'order', 'sales');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes every production order model to the current company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([ProductionOrder::class, ProductionOrderComponent::class, ProductionOrderOperation::class, ProductionOrderIssue::class]);

it('answers 404 for the other company\'s order on the page and in the actions', function () {
    $this->get(route('manufacturing.productionOrders.show', $this->b['order']->id))->assertNotFound();
    $this->get(route('manufacturing.productionOrders.show', $this->a['order']->id))->assertOk();
    $this->get(route('manufacturing.productionOrders.index'))->assertOk()->assertSee('TO-1')->assertDontSee((string) $this->b['fx']['articles']['table']->number.' ', false);

    Livewire::test(ProductionOrderPage::class, ['orderId' => $this->b['order']->id])->assertNotFound();

    expect(fn () => app(ProductionOrderLifecycle::class)->finish($this->a['owner'], $this->a['company'], $this->b['order']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SaveProductionOrder::class)->update($this->a['owner'], $this->a['company'], $this->b['order'], ['notes' => 'x']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ProductionOrderLifecycle::class)->finish($this->a['owner'], $this->b['company'], $this->b['order']))->toThrow(AuthorizationException::class);
});

it('refuses another company\'s article or customer order row', function () {
    expect(fn () => app(SaveProductionOrder::class)->create($this->a['owner'], $this->a['company'], ['article_id' => $this->b['fx']['articles']['table']->id, 'quantity' => '1']))
        ->toThrow(ValidationException::class, 'Välj en artikel')
        ->and(fn () => app(SaveProductionOrder::class)->create($this->a['owner'], $this->a['company'], ['sales_document_id' => $this->b['sales']->id, 'line_key' => $this->b['sales']->rows[0]->line_key]))
        ->toThrow(ValidationException::class, 'kundorder');
});

it('keeps the purchase proposal, the labour and the work in progress inside the company', function () {
    ProductionLabourEntry::query()->count();
    $wip = WorkInProgress::at($this->a['company'], CarbonImmutable::today());

    expect($wip->lines)->toHaveCount(1)
        ->and($wip->lines[0]['order_id'])->toBe($this->a['order']->id)
        ->and($wip->valueOre)->toBe(785_024);

    $this->b['company']->refresh();
    $proposalB = app(PurchaseProposal::class)->lines($this->a['company']);
    expect(collect($proposalB)->pluck('article.company_id')->unique()->all())->toBeIn([[], [$this->a['company']->id]]);
});
