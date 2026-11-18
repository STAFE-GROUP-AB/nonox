<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Livewire\LotsPage;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockLotLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockBalanceCheck;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Connect;
use Tests\Support\Inventory;

/*
 * AGENTS.md §13.2 for batch and serial numbers (ADR-081 E12): company A never
 * sees B's lots, lot levels or their movements, on the batch page, in the
 * trace or in the balance check; B's ids answer 404.
 */

beforeEach(function () {
    Mail::fake();
    Connect::setUp();

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Connect::company();
        $this->actingAs($owner);
        Inventory::enable($company, $owner);
        $places = Inventory::locations($company, $owner);
        $article = Inventory::article($company, $owner, "{$name}-1", "{$name}vara", ['tracking' => 'BATCH']);
        $receipt = app(SaveReceipt::class)($owner, $company, ['lines' => [['article_id' => $article->id, 'location_id' => $places['bin1']->id, 'quantity' => '3', 'unit_cost' => '50']]]);
        app(ReceiveStock::class)($owner, $company, $receipt, [$receipt->lines()->sole()->id => ['quantity' => '3', 'lot_code' => "{$name}-LOT", 'expires_on' => '2027-01-01']], CarbonImmutable::parse('2026-03-01'));
        $this->{$side} = compact('owner', 'company', 'places', 'article');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes lots, lot levels and their movements to the current company', function () {
    foreach ([StockLot::query(), StockLotLevel::query(), StockMovement::query()->whereNotNull('lot_id')] as $query) {
        expect($query->count())->toBeGreaterThan(0)
            ->and($query->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
    }
});

it('shows only the own company\'s lots and answers 404 for the other company\'s lot', function () {
    $foreign = CompanyScope::bypass(fn () => StockLot::query()->where('company_id', $this->b['company']->id)->firstOrFail());

    expect(StockLot::query()->find($foreign->id))->toBeNull()
        ->and(StockMovement::query()->where('lot_id', $foreign->id)->count())->toBe(0);

    Livewire::test(LotsPage::class)->assertSee('Alfa-LOT')->assertDontSee('Beta-LOT')
        ->call('show', $foreign->id)->assertNotFound();

    Livewire::withQueryParams(['batch' => $foreign->id])->test(LotsPage::class)->assertNotFound();
});

it('checks the lot balances inside the own company only', function () {
    CurrentCompany::set($this->b['company']);
    StockLotLevel::query()->update(['quantity_milli' => 1000]);
    CurrentCompany::set($this->a['company']);

    expect(StockBalanceCheck::lotDifferences($this->a['company']))->toBe([])
        ->and(StockBalanceCheck::lotDifferences($this->b['company']))->not->toBe([]);
});
