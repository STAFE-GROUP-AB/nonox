<?php

declare(strict_types=1);

use App\Models\Article;
use App\Modules\Inventory\Actions\Planning\GenerateDemandForecast;
use App\Modules\Inventory\Actions\Planning\SaveDemandForecast;
use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Inventory\Domain\ForecastSource;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Livewire\ForecastPage;
use App\Modules\Inventory\Models\DemandForecast;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Planning\DemandHistory;
use App\Modules\Inventory\Planning\ForecastDemand;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * AGENTS.md §13.2 for the demand forecast (ADR-081 gap 5): company A never
 * sees, changes or computes B's forecast, planning data or history, though
 * both use the same article number; B's ids answer 404.
 */

beforeEach(function () {
    $monday = CarbonImmutable::today()->startOfWeek();
    $lastMonth = CarbonImmutable::today()->startOfMonth()->subMonth()->toDateString();

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        $this->actingAs($owner);
        Inventory::enable($company, $owner);
        $places = Inventory::locations($company, $owner);
        $article = Inventory::article($company, $owner, 'SAMMA-1', "{$name}kopp", ['planning_method' => 'MRP', 'lead_time_days' => '7']);
        StockMovement::query()->create(['article_id' => $article->id, 'location_id' => $places['bin1']->id, 'quantity_milli' => -60000, 'type' => MovementType::Pick, 'occurred_on' => $lastMonth]);
        DemandForecast::query()->create(['article_id' => $article->id, 'period_kind' => 'WEEK', 'period_start' => $monday, 'quantity_milli' => 3000, 'source' => ForecastSource::Manual]);
        $this->{$side} = compact('owner', 'company', 'article', 'places');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes the forecast and the history to the current company', function () {
    expect(DemandForecast::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id])
        ->and(DemandForecast::query()->count())->toBe(1)
        ->and(DemandForecast::query()->find(DemandForecast::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->value('id')))->toBeNull()
        ->and(Article::query()->pluck('id')->all())->toBe([$this->a['article']->id]);
});

it('shows only the current company\'s articles and figures on the page', function () {
    Livewire::test(ForecastPage::class)
        ->assertSee('Alfakopp')
        ->assertDontSee('Betakopp');
});

it('answers 404 for the other company\'s article on every write', function () {
    $other = $this->b['article'];
    $monday = CarbonImmutable::today()->startOfWeek();

    expect(fn () => app(SaveDemandForecast::class)($this->a['owner'], $this->a['company'], $other, ForecastPeriodKind::Week, $monday, '5'))->toThrow(ModelNotFoundException::class);

    Livewire::test(ForecastPage::class)->call('setCell', $other->id, $monday->toDateString(), '5')->assertNotFound();

    expect(DemandForecast::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->sole()->quantity_milli)->toBe(3000);
});

it('computes only for the current company and leaves the other\'s forecast alone', function () {
    $result = app(GenerateDemandForecast::class)($this->a['owner'], $this->a['company'], [$this->a['article']->id, $this->b['article']->id], ForecastPeriodKind::Week, 13, 3, true);

    expect($result['articles'])->toBe(1)
        ->and(DemandForecast::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count())->toBe(13)
        ->and(DemandForecast::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->count())->toBe(1)
        ->and(DemandForecast::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->sole()->source)->toBe(ForecastSource::Manual);
});

it('reads no other company\'s history or weekly buckets', function () {
    $window = DemandHistory::window(CarbonImmutable::today(), 3);

    expect($window->consumption([$this->a['article']->id, $this->b['article']->id]))->toHaveKey($this->a['article']->id)
        ->not->toHaveKey($this->b['article']->id);

    $weekly = app(ForecastDemand::class)->weekly([$this->a['article']->id, $this->b['article']->id], CarbonImmutable::today()->startOfWeek(), 2);

    expect(array_sum($weekly[$this->b['article']->id]))->toBe(0)
        ->and(array_sum($weekly[$this->a['article']->id]))->toBe(3000);
});

it('keeps each company\'s planning data on its own article', function () {
    expect(Article::withoutGlobalScopes()->where('number', 'SAMMA-1')->count())->toBe(2)
        ->and(Article::query()->where('number', 'SAMMA-1')->sole()->company_id)->toBe($this->a['company']->id);
});
