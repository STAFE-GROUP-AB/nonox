<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Kitchen\Actions\RegisterStaffMeal;
use App\Modules\Kitchen\Actions\RegisterWaste;
use App\Modules\Kitchen\Livewire\VariancePage;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\Kitchen\Support\TheoreticalConsumption;
use App\Modules\Kitchen\Support\VarianceReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Inventory;
use Tests\Support\Kitchen;

/*
 * Theoretical consumption and the variance report (ADR-073 §5, §8, rule
 * sheet RS-C7, RS-C8): fixture F-RS-3 for Champinjoner in the week
 * 2026-11-02 – 2026-11-08 to the milli and the öre, "Sålt utan recept",
 * waste registered after the count (F-RS-5), and a fixed number of
 * queries.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 18:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Kitchen::company();
    $this->actingAs($this->owner);
    $this->bin = $this->places['bin1'];
    $this->fx = Kitchen::fixtures($this->company, $this->owner, $this->bin, '6');
    $this->a = $this->fx['articles'];
    $this->counts = app(StockCounts::class);

    // Opening count 2026-11-01: 6 kg of champinjoner, as the system says.
    $this->opening = $this->counts->start($this->owner, $this->company, $this->bin->id, CarbonImmutable::parse('2026-11-01'));
    $this->counts->record($this->owner, $this->company, countLine($this->opening, $this->a['champinjoner']->id), '6');
    $this->opening = $this->counts->complete($this->owner, $this->company, $this->opening);

    // The week: 20 kg received, 140 sold and 2 returned, 1,5 kg thrown away, 6 staff meals of the dish.
    $this->travelTo(CarbonImmutable::parse('2026-11-08 22:00:00'));
    Inventory::receive($this->company, $this->owner, $this->a['champinjoner'], $this->bin, '20', '60', '2026-11-03');
    $this->till = Kitchen::till($this->company, $this->owner, $this->bin);
    Kitchen::sale($this->till, '2026-11-04', [[$this->a['dish'], 140_000, 140 * 16_875]]);
    Kitchen::sale($this->till, '2026-11-05', [[$this->a['dish'], -2_000, -2 * 16_875]]);
    app(RegisterWaste::class)($this->owner, $this->company, ['kind' => WasteEntry::INGREDIENT, 'article_id' => $this->a['champinjoner']->id, 'quantity' => '1,5', 'location_id' => $this->bin->id, 'reason' => 'UTGANGET', 'date' => '2026-11-05']);
    app(RegisterStaffMeal::class)($this->owner, $this->company, ['date' => '2026-11-06', 'meal' => 'LUNCH', 'article_id' => $this->a['dish']->id, 'quantity' => '6', 'reason' => 'PERSONALMAT', 'location_id' => $this->bin->id]);

    // Closing count 2026-11-08: 4,8 kg.
    $this->closing = $this->counts->start($this->owner, $this->company, $this->bin->id, CarbonImmutable::parse('2026-11-08'));
    $this->counts->record($this->owner, $this->company, countLine($this->closing, $this->a['champinjoner']->id), '4,8', 'OTHER');
    $this->closing = $this->counts->complete($this->owner, $this->company, $this->closing);
});

function countLine(StockCount $count, int $articleId): StockCountLine
{
    return StockCountLine::query()->where('stock_count_id', $count->id)->where('article_id', $articleId)->firstOrFail();
}

it('computes theoretical consumption from the till rows, returns reducing (RS-C7)', function () {
    $theory = TheoreticalConsumption::between($this->company, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08'));

    expect($theory->sold)->toBe([$this->a['dish']->id => 138_000])
        ->and($theory->ingredients[$this->a['champinjoner']->id])->toBe(18_395)
        ->and($theory->withoutRecipe)->toBe([]);
});

it('computes the variance for Champinjoner to the milli and the öre (F-RS-3)', function () {
    $report = VarianceReport::between($this->company, $this->opening, $this->closing);
    $line = collect($report->lines)->firstWhere('article_id', $this->a['champinjoner']->id);

    expect($line)->toMatchArray([
        'opening_milli' => 6_000,
        'receipts_milli' => 20_000,
        'theoretical_milli' => 18_395,
        'waste_milli' => 1_500,
        'waste_by_reason' => ['UTGANGET' => 1_500],
        'staff_milli' => 800,
        'other_milli' => 0,
        'closing_milli' => 4_800,
        'actual_milli' => 21_200,
        'explained_milli' => 20_695,
        'variance_milli' => 505,
        'unit_cost_ore' => 6_000,
        'variance_ore' => 3_030,
        'waste_ore' => 9_000,
        'share_bp' => 275,
        'stock_class' => 'GOODS_FOR_RESALE',
    ]);
});

it('lists dishes sold without a recipe and never guesses them (F-RS-5)', function () {
    $special = Kitchen::dish($this->company, $this->owner, 'M-8', 'Dagens special', '120,00');
    Kitchen::sale($this->till, '2026-11-06', [[$special, 3_000, 36_000]]);

    $report = VarianceReport::between($this->company, $this->opening, $this->closing);

    expect($report->withoutRecipe)->toBe([$special->id => 3_000])
        ->and(collect($report->lines)->firstWhere('article_id', $this->a['champinjoner']->id)['variance_milli'])->toBe(505);
});

it('shows waste dated in the period but registered after the count (F-RS-5)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00:00'));
    app(RegisterWaste::class)($this->owner, $this->company, ['kind' => WasteEntry::INGREDIENT, 'article_id' => $this->a['lok']->id, 'quantity' => '0,5', 'location_id' => $this->bin->id, 'reason' => 'FORDARVAT', 'date' => '2026-11-07']);

    $report = VarianceReport::between($this->company, $this->opening, $this->closing);

    expect($report->lateWaste)->toHaveCount(1)
        ->and($report->lateWaste[0])->toMatchArray(['article' => 'Gul lök', 'registered_on' => '2026-11-07', 'quantity_milli' => 500]);
});

it('shows the report on the page with the source of the sales', function () {
    Livewire::withQueryParams(['fran' => $this->opening->id, 'till' => $this->closing->id])
        ->test(VariancePage::class)
        ->assertSee('Champinjoner')
        ->assertSee('30,30')
        ->assertSee('2,8 %')
        ->assertSee('enligt kassans försäljningsrader');
});

it('refuses two counts in the wrong order', function () {
    expect(fn () => VarianceReport::between($this->company, $this->closing, $this->opening))->toThrow(ValidationException::class, 'före');
});

it('runs a fixed number of queries whatever the number of sales rows and ingredients', function () {
    $measure = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        VarianceReport::between($this->company, $this->opening, $this->closing);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $before = $measure();

    foreach (range(1, 20) as $day) {
        Kitchen::sale($this->till, '2026-11-0'.(2 + $day % 6), [[$this->a['dish'], 1_000, 16_875], [$this->a['dish'], 2_000, 33_750]]);
    }

    expect($measure())->toBe($before);
});
