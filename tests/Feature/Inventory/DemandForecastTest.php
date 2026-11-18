<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Livewire\Invoicing\Articles;
use App\Models\Article;
use App\Models\AuditLog;
use App\Modules\Inventory\Actions\Planning\GenerateDemandForecast;
use App\Modules\Inventory\Actions\Planning\SaveDemandForecast;
use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Inventory\Domain\ForecastSource;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\PlanningMethod;
use App\Modules\Inventory\Livewire\ForecastPage;
use App\Modules\Inventory\Models\DemandForecast;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Planning\DemandHistory;
use App\Modules\Inventory\Planning\ForecastDemand;
use App\Modules\Inventory\Planning\ForecastPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * Planning data on articles and the demand forecast per article (ADR-081
 * gap 5, E15): the data the MRP engine reads. Weeks start on a Monday, the
 * horizon is 13 weeks by default and 52 at most, a computed figure is a plain
 * average anyone can recompute, and a typed figure is never overwritten
 * unless asked.
 */

/** A date every test counts from: a Wednesday. */
function e15Today(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-18');
}

/** Consumption of an article on a day, as a stock movement out. */
function e15Consume(object $test, Article $article, string $quantity, string $day, MovementType $type = MovementType::Pick): void
{
    StockMovement::query()->create([
        'article_id' => $article->id,
        'location_id' => $test->places['bin1']->id,
        'quantity_milli' => -(int) round(((float) $quantity) * 1000),
        'type' => $type,
        'occurred_on' => $day,
    ]);
}

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->article = Inventory::article($this->company, $this->owner, 'F-1', 'Kaffekopp');
    $this->monday = CarbonImmutable::parse('2026-11-16');
});

it('gives an article no planning until someone sets it', function () {
    $article = $this->article->fresh();

    expect($article->planning_method)->toBe(PlanningMethod::None)
        ->and($article->lead_time_days)->toBeNull()
        ->and($article->safety_stock_milli)->toBe(0)
        ->and($article->lot_minimum_milli)->toBeNull()
        ->and($article->lot_multiple_milli)->toBeNull();
});

it('saves the planning data on an article', function () {
    $article = app(SaveArticle::class)($this->owner, $this->company, [
        'number' => 'F-1', 'name' => 'Kaffekopp', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500,
        'is_stock_item' => true,
        'lead_time_days' => '14', 'safety_stock' => '25', 'lot_minimum' => '100', 'lot_multiple' => '12,5', 'planning_method' => 'MRP',
    ], $this->article)->fresh();

    expect($article->lead_time_days)->toBe(14)
        ->and($article->safety_stock_milli)->toBe(25000)
        ->and($article->lot_minimum_milli)->toBe(100000)
        ->and($article->lot_multiple_milli)->toBe(12500)
        ->and($article->planning_method)->toBe(PlanningMethod::Mrp);

    $cleared = app(SaveArticle::class)($this->owner, $this->company, [
        'number' => 'F-1', 'name' => 'Kaffekopp', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500,
        'lead_time_days' => '', 'safety_stock' => '', 'lot_minimum' => '', 'lot_multiple' => '', 'planning_method' => '',
    ], $article)->fresh();

    expect($cleared->lead_time_days)->toBeNull()
        ->and($cleared->safety_stock_milli)->toBe(0)
        ->and($cleared->lot_minimum_milli)->toBeNull()
        ->and($cleared->planning_method)->toBe(PlanningMethod::None);
});

it('refuses planning data that cannot be right', function (array $input, string $field) {
    expect(fn () => app(SaveArticle::class)($this->owner, $this->company, [
        'number' => 'F-1', 'name' => 'Kaffekopp', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500, ...$input,
    ], $this->article))->toThrow(ValidationException::class);

    try {
        app(SaveArticle::class)($this->owner, $this->company, ['number' => 'F-1', 'name' => 'Kaffekopp', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500, ...$input], $this->article);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'lead time over a year' => [['lead_time_days' => '400'], 'lead_time_days'],
    'negative lead time' => [['lead_time_days' => '-1'], 'lead_time_days'],
    'fractional lead time' => [['lead_time_days' => '2,5'], 'lead_time_days'],
    'negative safety stock' => [['safety_stock' => '-3'], 'safety_stock'],
    'zero lot minimum' => [['lot_minimum' => '0'], 'lot_minimum'],
    'zero lot multiple' => [['lot_multiple' => '0'], 'lot_multiple'],
    'unknown method' => [['planning_method' => 'GUESS'], 'planning_method'],
    'text as quantity' => [['safety_stock' => 'mycket'], 'safety_stock'],
]);

it('lets only someone with mrp:run change the planning data', function () {
    $accountant = Bookkeeping::member($this->company, 'accountant');

    expect(fn () => app(SaveArticle::class)($accountant, $this->company, [
        'number' => 'F-1', 'name' => 'Kaffekopp', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500, 'lead_time_days' => '3',
    ], $this->article))->toThrow(AuthorizationException::class);

    expect($this->article->fresh()->lead_time_days)->toBeNull();

    // The same save without the planning keys is untouched by the rule.
    $saved = app(SaveArticle::class)($accountant, $this->company, [
        'number' => 'F-1', 'name' => 'Kaffekopp 2', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500,
    ], $this->article);
    expect($saved->name)->toBe('Kaffekopp 2');
});

it('accepts planning values sent back unchanged without mrp:run, and refuses a change', function () {
    $article = app(SaveArticle::class)($this->owner, $this->company, [
        'number' => 'F-1', 'name' => 'Kaffekopp', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500,
        'lead_time_days' => '14', 'safety_stock' => '25', 'lot_minimum' => '100', 'planning_method' => 'MRP',
    ], $this->article);
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $base = ['number' => 'F-1', 'name' => 'Kaffekopp', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500];

    $saved = app(SaveArticle::class)($accountant, $this->company, [...$base, 'name' => 'Nytt namn', 'lead_time_days' => '14', 'safety_stock' => '25,000', 'lot_minimum' => '100', 'lot_multiple' => '', 'planning_method' => 'MRP'], $article);
    expect($saved->name)->toBe('Nytt namn');

    expect(fn () => app(SaveArticle::class)($accountant, $this->company, [...$base, 'lead_time_days' => '15'], $article))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveArticle::class)($accountant, $this->company, [...$base, 'planning_method' => 'NONE'], $article))->toThrow(AuthorizationException::class);
});

it('keeps the database from storing a week that does not start on a Monday or a negative figure', function () {
    $row = fn (string $kind, string $start, int $milli = 1000): array => [
        'company_id' => $this->company->id, 'article_id' => $this->article->id, 'period_start' => $start,
        'period_kind' => $kind, 'quantity_milli' => $milli, 'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now(),
    ];

    foreach ([['WEEK', '2026-11-17'], ['MONTH', '2026-11-02'], ['QUARTER', '2026-10-01']] as [$kind, $start]) {
        expect(fn () => DB::transaction(fn () => DB::table('demand_forecasts')->insert($row($kind, $start))))->toThrow(QueryException::class);
    }

    expect(fn () => DB::transaction(fn () => DB::table('demand_forecasts')->insert($row('WEEK', '2026-11-16', -1))))->toThrow(QueryException::class);

    DB::table('demand_forecasts')->insert($row('WEEK', '2026-11-16'));
    expect(fn () => DB::transaction(fn () => DB::table('demand_forecasts')->insert($row('WEEK', '2026-11-16'))))->toThrow(QueryException::class);
});

it('builds the horizon: 13 weeks by default, never more than 52', function () {
    $kind = ForecastPeriodKind::Week;
    $periods = ForecastPeriod::horizon($kind, e15Today(), $kind->defaultCount());

    expect($periods)->toHaveCount(13)
        ->and($periods[0]->start->toDateString())->toBe('2026-11-16')
        ->and($periods[0]->end->toDateString())->toBe('2026-11-22')
        ->and($periods[12]->start->toDateString())->toBe('2027-02-08')
        ->and(ForecastPeriod::horizon($kind, e15Today(), 500))->toHaveCount(52)
        ->and(ForecastPeriod::horizon($kind, e15Today(), 0))->toHaveCount(1)
        ->and($kind->lastStart(e15Today())->toDateString())->toBe('2027-11-08');

    $months = ForecastPeriod::horizon(ForecastPeriodKind::Month, e15Today(), 99);
    expect($months)->toHaveCount(12)
        ->and($months[0]->start->toDateString())->toBe('2026-11-01')
        ->and($months[0]->end->toDateString())->toBe('2026-11-30')
        ->and($months[2]->start->toDateString())->toBe('2027-01-01');
});

it('saves, replaces and clears one forecast figure', function () {
    $save = app(SaveDemandForecast::class);

    $forecast = $save($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, $this->monday, '12,5', e15Today());

    expect($forecast->quantity_milli)->toBe(12500)
        ->and($forecast->source)->toBe(ForecastSource::Manual)
        ->and($forecast->created_by)->toBe($this->owner->id);

    $save($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, $this->monday, '40', e15Today());
    expect(DemandForecast::query()->count())->toBe(1)
        ->and(DemandForecast::query()->sole()->quantity_milli)->toBe(40000);

    expect($save($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, $this->monday, '', e15Today()))->toBeNull()
        ->and(DemandForecast::query()->count())->toBe(0);
});

it('turns a computed figure into a typed one when someone edits it', function () {
    DemandForecast::query()->create(['article_id' => $this->article->id, 'period_kind' => 'WEEK', 'period_start' => $this->monday, 'quantity_milli' => 5000, 'source' => ForecastSource::History]);

    app(SaveDemandForecast::class)($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, $this->monday, '5', e15Today());

    expect(DemandForecast::query()->sole()->source)->toBe(ForecastSource::Manual);
});

it('refuses a figure the forecast cannot hold', function (string $kind, string $start, ?string $quantity, string $reason) {
    expect(fn () => app(SaveDemandForecast::class)($this->owner, $this->company, $this->article, ForecastPeriodKind::from($kind), CarbonImmutable::parse($start), $quantity, e15Today()))
        ->toThrow(ValidationException::class);

    expect(DemandForecast::query()->count())->toBe(0);
})->with([
    'a Tuesday' => ['WEEK', '2026-11-17', '5', 'not a Monday'],
    'a week that has passed' => ['WEEK', '2026-11-09', '5', 'past'],
    'more than 52 weeks ahead' => ['WEEK', '2027-11-15', '5', 'too far'],
    'not the first of a month' => ['MONTH', '2026-12-02', '5', 'not a month start'],
    'a month that has passed' => ['MONTH', '2026-10-01', '5', 'past'],
    'more than 12 months ahead' => ['MONTH', '2027-11-01', '5', 'too far'],
    'a negative figure' => ['WEEK', '2026-11-16', '-1', 'negative'],
    'text' => ['WEEK', '2026-11-16', 'några', 'not a number'],
    'a figure that is too large' => ['WEEK', '2026-11-16', '9999999999', 'too large'],
]);

it('allows the last week of the horizon and the current week', function () {
    $save = app(SaveDemandForecast::class);

    expect($save($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, CarbonImmutable::parse('2027-11-08'), '1', e15Today()))->not->toBeNull()
        ->and($save($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, CarbonImmutable::parse('2026-11-16'), '1', e15Today()))->not->toBeNull();
});

it('forecasts only stocked articles', function () {
    $service = Inventory::article($this->company, $this->owner, 'T-1', 'Konsulttimme', ['is_stock_item' => false]);

    expect(fn () => app(SaveDemandForecast::class)($this->owner, $this->company, $service, ForecastPeriodKind::Week, $this->monday, '5', e15Today()))
        ->toThrow(ValidationException::class, 'lagerförda');
});

it('lets those who view see the forecast but not change it', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    foreach ([$auditor, $accountant] as $viewer) {
        expect(fn () => app(SaveDemandForecast::class)($viewer, $this->company, $this->article, ForecastPeriodKind::Week, $this->monday, '5', e15Today()))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(GenerateDemandForecast::class)($viewer, $this->company, null, ForecastPeriodKind::Week, 13, 3, false, e15Today()))
            ->toThrow(AuthorizationException::class);

        $this->actingAs($viewer);
        Livewire::test(ForecastPage::class)->assertOk();
    }

    expect(app(SaveDemandForecast::class)($bookkeeper, $this->company, $this->article, ForecastPeriodKind::Week, $this->monday, '5', e15Today()))->not->toBeNull();

    $stockClerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->actingAs($stockClerk);
    Livewire::test(ForecastPage::class)->assertForbidden();
});

it('writes the audit log with ids, enums and amounts only', function () {
    app(SaveDemandForecast::class)($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, $this->monday, '7', e15Today());
    app(SaveDemandForecast::class)($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, $this->monday, '', e15Today());

    $saved = AuditLog::query()->where('action', 'demand_forecast.saved')->sole();

    expect($saved->payload)->toEqual([
        'article_id' => $this->article->id, 'period_kind' => 'WEEK', 'period_start' => '2026-11-16', 'quantity_milli' => 7000, 'source' => 'MANUAL',
    ])->and(AuditLog::query()->where('action', 'demand_forecast.deleted')->count())->toBe(1)
        ->and(json_encode(AuditLog::query()->where('action', 'like', 'demand_forecast.%')->pluck('payload')))->not->toContain('Kaffekopp');
});

it('finds the demand of the last complete months and nothing else', function () {
    $other = Inventory::article($this->company, $this->owner, 'F-2', 'Tallrik');
    $window = DemandHistory::window(e15Today(), 3);

    expect($window->from->toDateString())->toBe('2026-08-01')
        ->and($window->to->toDateString())->toBe('2026-11-01')
        ->and($window->lastDay()->toDateString())->toBe('2026-10-31')
        ->and($window->days())->toBe(92);

    e15Consume($this, $this->article, '30', '2026-08-01');
    e15Consume($this, $this->article, '20', '2026-10-31', MovementType::PosSale);
    e15Consume($this, $this->article, '10', '2026-09-15', MovementType::RecipeSale);
    e15Consume($this, $this->article, '5', '2026-09-20', MovementType::ProductionIssue);
    // Outside the window, or not demand.
    e15Consume($this, $this->article, '99', '2026-07-31');
    e15Consume($this, $this->article, '99', '2026-11-01');
    e15Consume($this, $this->article, '99', '2026-09-10', MovementType::Scrap);
    e15Consume($this, $this->article, '99', '2026-09-10', MovementType::InternalUse);
    e15Consume($this, $this->article, '99', '2026-09-10', MovementType::Transfer);
    // A cancelled production order brings components back: net of it.
    StockMovement::query()->create(['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => 5000, 'type' => MovementType::ProductionIssue, 'occurred_on' => '2026-10-01']);
    // Only returns in: never negative demand.
    StockMovement::query()->create(['article_id' => $other->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => 7000, 'type' => MovementType::ProductionIssue, 'occurred_on' => '2026-10-01']);

    $consumed = $window->consumption([$this->article->id, $other->id]);

    expect($consumed)->toBe([$this->article->id => 60000])
        // 60 over 92 days is 0,652 a day: 4,565 a week, and 20 a month.
        ->and($window->perWeek(60000))->toBe(4565)
        ->and($window->perMonth(60000))->toBe(20000)
        ->and($window->perMonth(10000))->toBe(3333)
        ->and($window->perMonth(10001))->toBe(3334);
});

it('clamps the history to 1 to 24 months', function () {
    expect(DemandHistory::window(e15Today(), 0)->months)->toBe(1)
        ->and(DemandHistory::window(e15Today(), 99)->months)->toBe(24)
        ->and(DemandHistory::window(CarbonImmutable::parse('2027-01-10'), 2)->from->toDateString())->toBe('2026-11-01');
});

it('computes the forecast from history and keeps what a person typed', function () {
    e15Consume($this, $this->article, '60', '2026-09-10');
    $save = app(SaveDemandForecast::class);
    $save($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, CarbonImmutable::parse('2026-11-23'), '99', e15Today());

    $result = app(GenerateDemandForecast::class)($this->owner, $this->company, null, ForecastPeriodKind::Week, 13, 3, false, e15Today());

    expect($result)->toBe(['articles' => 1, 'written' => 12, 'removed' => 0, 'kept_manual' => 1]);

    $rows = DemandForecast::query()->orderBy('period_start')->get();
    expect($rows)->toHaveCount(13)
        ->and($rows->firstWhere('period_start', CarbonImmutable::parse('2026-11-16'))->quantity_milli)->toBe(4565)
        ->and($rows->firstWhere('period_start', CarbonImmutable::parse('2026-11-16'))->source)->toBe(ForecastSource::History)
        ->and($rows->firstWhere('period_start', CarbonImmutable::parse('2026-11-23'))->quantity_milli)->toBe(99000)
        ->and($rows->firstWhere('period_start', CarbonImmutable::parse('2026-11-23'))->source)->toBe(ForecastSource::Manual);

    // Again: the same result, no duplicates.
    app(GenerateDemandForecast::class)($this->owner, $this->company, null, ForecastPeriodKind::Week, 13, 3, false, e15Today());
    expect(DemandForecast::query()->count())->toBe(13);

    // Overwriting typed values too.
    $result = app(GenerateDemandForecast::class)($this->owner, $this->company, null, ForecastPeriodKind::Week, 13, 3, true, e15Today());
    expect($result['written'])->toBe(13)
        ->and(DemandForecast::query()->where('source', 'MANUAL')->count())->toBe(0);
});

it('computes a monthly forecast and removes computed figures when the history is gone', function () {
    e15Consume($this, $this->article, '60', '2026-09-10');
    app(GenerateDemandForecast::class)($this->owner, $this->company, [$this->article->id], ForecastPeriodKind::Month, 3, 3, false, e15Today());

    expect(DemandForecast::query()->pluck('quantity_milli')->all())->toBe([20000, 20000, 20000])
        ->and(DemandForecast::query()->pluck('period_kind')->unique()->all())->toBe([ForecastPeriodKind::Month]);

    // A history window that holds no demand: the computed figures go.
    $result = app(GenerateDemandForecast::class)($this->owner, $this->company, [$this->article->id], ForecastPeriodKind::Month, 3, 1, false, e15Today());
    expect($result['removed'])->toBe(3)
        ->and(DemandForecast::query()->count())->toBe(0);
});

it('computes only for the articles chosen, and only stocked ones', function () {
    $other = Inventory::article($this->company, $this->owner, 'F-2', 'Tallrik');
    e15Consume($this, $this->article, '60', '2026-09-10');
    e15Consume($this, $other, '60', '2026-09-10');

    $result = app(GenerateDemandForecast::class)($this->owner, $this->company, [$other->id], ForecastPeriodKind::Week, 2, 3, false, e15Today());

    expect($result['articles'])->toBe(1)
        ->and(DemandForecast::query()->pluck('article_id')->unique()->all())->toBe([$other->id]);
});

it('refuses a history or horizon outside the limits', function (int $periods, int $months) {
    expect(fn () => app(GenerateDemandForecast::class)($this->owner, $this->company, null, ForecastPeriodKind::Week, $periods, $months, false, e15Today()))
        ->toThrow(ValidationException::class);
})->with([[0, 3], [53, 3], [13, 0], [13, 25]]);

it('audits a computation with counts only', function () {
    e15Consume($this, $this->article, '60', '2026-09-10');
    app(GenerateDemandForecast::class)($this->owner, $this->company, null, ForecastPeriodKind::Week, 13, 3, false, e15Today());

    expect(AuditLog::query()->where('action', 'demand_forecast.generated')->sole()->payload)->toEqual([
        'period_kind' => 'WEEK', 'periods' => 13, 'history_months' => 3, 'overwrite_manual' => false,
        'articles' => 1, 'written' => 13, 'removed' => 0, 'kept_manual' => 0,
    ]);
});

it('reads the forecast as weekly buckets for the planning engine', function () {
    $save = app(SaveDemandForecast::class);
    $save($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, CarbonImmutable::parse('2026-11-16'), '10', e15Today());
    // A month figure of 31 over November 16 to December 6: it is spread by days.
    $save($this->owner, $this->company, $this->article, ForecastPeriodKind::Month, CarbonImmutable::parse('2026-12-01'), '31', e15Today());
    // A week figure beats the month's share for that week (Dec 7 to 13).
    $save($this->owner, $this->company, $this->article, ForecastPeriodKind::Week, CarbonImmutable::parse('2026-12-07'), '2', e15Today());
    $empty = Inventory::article($this->company, $this->owner, 'F-2', 'Tallrik');

    $weekly = app(ForecastDemand::class)->weekly([$this->article->id, $empty->id], $this->monday, 5);

    expect($weekly[$this->article->id])->toBe([
        '2026-11-16' => 10000,
        // The week of Nov 23: no figure (November has none).
        '2026-11-23' => 0,
        // Nov 30 to Dec 6: December 1 to 6 are six days of 31: 6 units.
        '2026-11-30' => 6000,
        '2026-12-07' => 2000,
        // Dec 14 to 20: seven days of 31.
        '2026-12-14' => 7000,
    ])->and($weekly[$empty->id])->toBe(array_fill_keys(['2026-11-16', '2026-11-23', '2026-11-30', '2026-12-07', '2026-12-14'], 0));
});

it('splits a month figure over the weeks so that they add up exactly', function () {
    DemandForecast::query()->create(['article_id' => $this->article->id, 'period_kind' => 'MONTH', 'period_start' => '2026-11-01', 'quantity_milli' => 100000, 'source' => ForecastSource::Manual]);
    DemandForecast::query()->create(['article_id' => $this->article->id, 'period_kind' => 'MONTH', 'period_start' => '2026-12-01', 'quantity_milli' => 100001, 'source' => ForecastSource::Manual]);

    $weekly = app(ForecastDemand::class)->weekly([$this->article->id], CarbonImmutable::parse('2026-10-26'), 10);

    // Weeks from Oct 26 to Jan 3 cover all of November and December; October is no forecast.
    expect(array_sum($weekly[$this->article->id]))->toBe(200001);
});

it('caps the weekly buckets at 52 weeks', function () {
    expect(app(ForecastDemand::class)->weekly([$this->article->id], $this->monday, 300)[$this->article->id])->toHaveCount(52)
        ->and(app(ForecastDemand::class)->weekly([], $this->monday, 13))->toBe([]);
});

it('does the same number of queries for the forecast reader whatever the number of articles', function () {
    $count = function (array $ids): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ForecastDemand::class)->weekly($ids, $this->monday, 13);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $ids = [$this->article->id];
    $small = $count($ids);

    foreach (range(2, 10) as $index) {
        $article = Inventory::article($this->company, $this->owner, "F-{$index}", "Artikel {$index}");
        DemandForecast::query()->create(['article_id' => $article->id, 'period_kind' => 'WEEK', 'period_start' => '2026-11-16', 'quantity_milli' => 1000, 'source' => ForecastSource::Manual]);
        $ids[] = $article->id;
    }

    expect($count($ids))->toBe($small);
});

it('saves a cell on the page with a toast, and clears it with another', function () {
    Livewire::test(ForecastPage::class)
        ->assertSee('Kaffekopp')
        ->call('setCell', $this->article->id, CarbonImmutable::today()->startOfWeek()->toDateString(), '8')
        ->assertDispatched('toast', message: 'Prognosen är sparad.')
        ->call('setCell', $this->article->id, CarbonImmutable::today()->startOfWeek()->toDateString(), '')
        ->assertDispatched('toast', message: 'Prognosen är borttagen.')
        ->call('setCell', $this->article->id, CarbonImmutable::today()->startOfWeek()->toDateString(), 'abc')
        ->assertDispatched('toast', type: 'error')
        ->call('setCell', $this->article->id, 'inte-ett-datum', '5')
        ->assertDispatched('toast', type: 'error');

    expect(DemandForecast::query()->count())->toBe(0);
});

it('computes the forecast from the page, with a toast', function () {
    e15Consume($this, $this->article, '60', CarbonImmutable::today()->startOfMonth()->subMonth()->toDateString());

    Livewire::test(ForecastPage::class)
        ->set('months', '3')
        ->call('generate')
        ->assertDispatched('toast', message: 'Prognosen är beräknad för 1 artiklar: 13 värden skrivna och 0 egna värden behölls.');

    expect(DemandForecast::query()->where('source', 'HISTORY')->count())->toBe(13);
});

it('shows 13 weeks by default, switches to months and never shows more than 52 weeks', function () {
    $component = Livewire::test(ForecastPage::class);
    expect($component->get('periods'))->toBe('13');

    $component->set('periods', '500')->assertSet('periods', '52')
        ->set('periods', '0')->assertSet('periods', '1')
        ->set('kind', 'manad')->assertSet('periods', '3')
        ->set('periods', '99')->assertSet('periods', '12');
});

it('hides the editing from someone who may only look', function () {
    DemandForecast::query()->create(['article_id' => $this->article->id, 'period_kind' => 'WEEK', 'period_start' => CarbonImmutable::today()->startOfWeek(), 'quantity_milli' => 7000, 'source' => ForecastSource::History]);
    $this->actingAs(Bookkeeping::member($this->company, 'auditor'));

    Livewire::test(ForecastPage::class)
        ->assertSee('Kaffekopp')
        ->assertDontSee('Beräkna från historik')
        ->call('setCell', $this->article->id, CarbonImmutable::today()->startOfWeek()->toDateString(), '1')
        ->assertForbidden();
});

it('lists the forecast with the same number of queries for 2 and 12 articles', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ForecastPage::class)->set('periods', '52');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $stock = function (int $from, int $to): void {
        foreach (range($from, $to) as $index) {
            $article = Inventory::article($this->company, $this->owner, "Q-{$index}", "Artikel {$index}");
            e15Consume($this, $article, '10', CarbonImmutable::today()->startOfMonth()->subMonth()->toDateString());
            DemandForecast::query()->create(['article_id' => $article->id, 'period_kind' => 'WEEK', 'period_start' => CarbonImmutable::today()->startOfWeek(), 'quantity_milli' => 1000, 'source' => ForecastSource::Manual]);
        }
    };

    $stock(1, 1);
    // A first render warms what is cached per request (modules, settings).
    $queries();
    $small = $queries();
    $stock(2, 11);

    expect($queries())->toBe($small);
});

it('shows the planning fields on the article form for those who may run the planning and saves them', function () {
    Livewire::test(Articles::class)
        ->call('edit', $this->article->id)
        ->set('tab', 'avancerad')
        ->assertSee('Planeringsdata')
        ->set('form.planning_method', 'MRP')
        ->set('form.lead_time_days', '9')
        ->set('form.safety_stock', '4')
        ->set('form.lot_multiple', '6')
        ->call('save')
        ->assertHasNoErrors();

    $article = $this->article->fresh();
    expect($article->planning_method)->toBe(PlanningMethod::Mrp)
        ->and($article->lead_time_days)->toBe(9)
        ->and($article->safety_stock_milli)->toBe(4000)
        ->and($article->lot_multiple_milli)->toBe(6000);
});

it('leaves the planning data alone when someone without mrp:run saves the article form', function () {
    app(SaveArticle::class)($this->owner, $this->company, [
        'number' => 'F-1', 'name' => 'Kaffekopp', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500, 'planning_method' => 'MRP', 'lead_time_days' => '9',
    ], $this->article);

    $accountant = Bookkeeping::member($this->company, 'accountant');
    $this->actingAs($accountant);

    Livewire::test(Articles::class)
        ->call('edit', $this->article->id)
        ->set('tab', 'avancerad')
        ->assertSee('Planeringsdata')
        ->assertSee('Du kan se uppgifterna men inte ändra dem.');

    expect($this->article->fresh()->planning_method)->toBe(PlanningMethod::Mrp);
});
