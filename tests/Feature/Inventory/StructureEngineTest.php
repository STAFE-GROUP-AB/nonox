<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Inventory\Domain\StructureUnits;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Inventory\Structures\StructureWriter;
use App\Modules\Inventory\Structures\UnitCosts;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Kitchen;

/*
 * The structure engine in Lager (ADR-073 §2, shared with ADR-071's bills of
 * materials): the loss formula, costing to the öre per the rule sheet's
 * fixtures F-RS-1 and F-RS-2, several levels, versions chosen by date,
 * units, and the refusals of F-RS-5.
 */

beforeEach(function () {
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Kitchen::company(kitchen: false);
    $this->actingAs($this->owner);
    $this->fx = Kitchen::fixtures($this->company, $this->owner, $this->places['bin1']);
    $this->on = CarbonImmutable::parse('2026-11-02');
});

it('computes the gross quantity with the shared loss formula, half up', function () {
    expect(StructureMath::gross(150, 2_000))->toBe(188)
        ->and(StructureMath::gross(1_200, 1_000))->toBe(1_333)
        ->and(StructureMath::gross(250, 400))->toBe(260)
        ->and(StructureMath::gross(400, 1_200))->toBe(455)
        ->and(StructureMath::gross(100, 0))->toBe(100);

    expect(fn () => StructureMath::gross(100, 10_000))->toThrow(InvalidArgumentException::class);
});

it('costs the sub-recipe Vitlökssmör to the öre (F-RS-1)', function () {
    $cost = StructureBook::for($this->company)->cost($this->fx['butter'], UnitCosts::at($this->company, $this->on), $this->on);

    expect(array_map(fn (array $row): array => [$row['gross_milli'], $row['unit_cost_ore'], $row['line_cost_ore']], $cost->rows))
        ->toBe([[900, 11_000, 9_900], [188, 5_000, 940]])
        ->and($cost->batchCostOre)->toBe(10_840)
        ->and($cost->perUnitOre)->toBe(10_840)
        ->and($cost->complete)->toBeTrue();
});

it('costs the dish Pasta med svamp to the öre, through the sub-recipe (F-RS-2)', function () {
    $cost = StructureBook::for($this->company)->cost($this->fx['pasta'], UnitCosts::at($this->company, $this->on), $this->on);

    expect(array_map(fn (array $row): array => [$row['gross_milli'], $row['unit_cost_ore'], $row['line_cost_ore']], $cost->rows))->toBe([
        [1_000, 3_200, 3_200],
        [1_333, 6_000, 7_998],
        [800, 4_500, 3_600],
        [260, 28_000, 7_280],
        [455, 1_500, 683],
        [100, 10_840, 1_084],
    ]);

    expect($cost->batchCostOre)->toBe(23_845)
        ->and($cost->perUnitOre)->toBe(2_385)
        ->and($cost->rows[5]['source'])->toBe('structure')
        ->and($cost->rows[0]['source'])->toBe(UnitCosts::VALUATION);

    // RS-C6 against the net menu price 168,75 kr.
    $net = $this->fx['articles']['dish']->price_ore->ore;
    expect($net)->toBe(16_875)
        ->and($net - $cost->perUnitOre)->toBe(14_490)
        ->and(StructureMath::share(14_490, $net))->toBe(8_587)
        ->and(StructureMath::share($cost->perUnitOre, $net))->toBe(1_413);
});

it('stores parmesan entered in grams as thousandths of a kg', function () {
    $row = $this->fx['pasta']->rows[3];

    expect($row)->quantity_milli->toBe(250)->loss_bp->toBe(400)->entered_quantity->toBe('250')->entered_unit->toBe('g');
});

it('explodes sold portions through every level and rounds once per ingredient (RS-C7)', function () {
    $book = StructureBook::for($this->company);
    $a = $this->fx['articles'];

    $exploded = $book->explodeRounded([$a['dish']->id => 138_000], $this->on);

    expect($exploded['components'][$a['champinjoner']->id])->toBe(18_395)
        ->and($exploded['components'][$a['smor']->id])->toBe(1_242)       // 900 × 100/1000 × 138 000/10 000
        ->and($exploded['components'][$a['vitlok']->id])->toBe(259)        // 188 × 0,1 × 13,8 = 259,44
        ->and($exploded['components'])->not->toHaveKey($a['vitloksmor']->id)
        ->and($exploded['missing'])->toBe([]);
});

it('chooses the version valid on the date, so a new version leaves earlier sales alone', function () {
    $a = $this->fx['articles'];

    $v2 = app(StructureWriter::class)->save($this->company, $this->owner, StructureKind::Recipe, $a['dish'], [
        'name' => 'Pasta med svamp',
        'valid_from' => '2026-11-05',
        'yield' => '10',
        'yield_unit' => BillOfMaterials::PORTIONS,
        'rows' => [['article_id' => $a['champinjoner']->id, 'quantity' => '2']],
    ]);

    $book = StructureBook::for($this->company);

    expect($v2->version)->toBe(2)
        ->and($book->validOn($a['dish']->id, CarbonImmutable::parse('2026-11-04'))->id)->toBe($this->fx['pasta']->id)
        ->and($book->validOn($a['dish']->id, CarbonImmutable::parse('2026-11-05'))->id)->toBe($v2->id)
        ->and($book->validOn($a['dish']->id, CarbonImmutable::parse('2026-09-30')))->toBeNull()
        ->and($book->explodeRounded([$a['dish']->id => 10_000], CarbonImmutable::parse('2026-11-04'))['components'][$a['champinjoner']->id])->toBe(1_333)
        ->and($book->explodeRounded([$a['dish']->id => 10_000], CarbonImmutable::parse('2026-11-06'))['components'][$a['champinjoner']->id])->toBe(2_000);

    // A saved version is never changed.
    expect(fn () => $this->fx['pasta']->update(['yield_milli' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $this->fx['pasta']->rows[0]->update(['quantity_milli' => 1]))->toThrow(LogicException::class);
});

it('lists an output without a valid structure as missing, never guessed (F-RS-5)', function () {
    $other = Kitchen::dish($this->company, $this->owner, 'M-9', 'Utan recept', '100,00');

    $exploded = StructureBook::for($this->company)->explodeRounded([$other->id => 3_000], $this->on);

    expect($exploded['components'])->toBe([])->and($exploded['missing'])->toBe([$other->id => 3_000]);
});

it('refuses a loop, a loss of 100 %, a zero yield and a unit without conversion (F-RS-5)', function () {
    $a = $this->fx['articles'];
    $writer = app(StructureWriter::class);
    $base = ['name' => 'X', 'yield' => '1', 'yield_unit' => BillOfMaterials::UNIT];

    // Smör made from Vitlökssmör, which is made from smör.
    expect(fn () => $writer->save($this->company, $this->owner, StructureKind::Recipe, $a['smor'], [...$base, 'rows' => [['article_id' => $a['vitloksmor']->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'ring');

    expect(fn () => $writer->save($this->company, $this->owner, StructureKind::Recipe, $a['dish'], [...$base, 'rows' => [['article_id' => $a['dish']->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'sig självt');

    expect(fn () => $writer->save($this->company, $this->owner, StructureKind::Recipe, $a['dish'], [...$base, 'rows' => [['article_id' => $a['lok']->id, 'quantity' => '1', 'loss' => '100']]]))
        ->toThrow(ValidationException::class, 'under 100 procent');

    expect(fn () => $writer->save($this->company, $this->owner, StructureKind::Recipe, $a['dish'], [...$base, 'yield' => '0', 'rows' => [['article_id' => $a['lok']->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'mer än noll');

    expect(fn () => $writer->save($this->company, $this->owner, StructureKind::Recipe, $a['dish'], [...$base, 'rows' => [['article_id' => $a['lok']->id, 'quantity' => '1', 'unit' => 'fl']]]))
        ->toThrow(ValidationException::class, 'omräkning');

    expect(BillOfMaterials::query()->count())->toBe(2);
});

it('converts g to kg and cl to bottles through the article\'s own conversion', function () {
    $vodka = Kitchen::ingredient($this->company, $this->owner, 'D-1', 'Vodka 70 cl', 'fl', '200,00');
    ArticleUnitConversion::create(['article_id' => $vodka->id, 'unit' => 'cl', 'factor_milli' => 70_000]);
    $drink = Kitchen::dish($this->company, $this->owner, 'M-2', 'Drink', '100,00', 2500);

    $saved = app(StructureWriter::class)->save($this->company, $this->owner, StructureKind::Recipe, $drink, [
        'name' => 'Drink', 'yield' => '1', 'yield_unit' => BillOfMaterials::PORTIONS,
        'rows' => [['article_id' => $vodka->id, 'quantity' => '4', 'unit' => 'cl']],
    ]);

    expect($saved->rows[0]->quantity_milli)->toBe(57)
        ->and(StructureUnits::convert(1_500, StructureUnits::factor('kg', 'g')))->toBe(2)
        ->and(StructureUnits::factor('l', 'dl'))->toBe(10_000)
        ->and(StructureUnits::choices('fl', ['cl' => 70_000]))->toBe(['fl', 'cl']);
});

it('marks a dish without a cost for an ingredient as incomplete, never as free', function () {
    $saffron = Kitchen::ingredient($this->company, $this->owner, 'R-8', 'Saffran', 'g', '');
    $dish = Kitchen::dish($this->company, $this->owner, 'M-3', 'Risotto', '150,00');
    $version = app(StructureWriter::class)->save($this->company, $this->owner, StructureKind::Recipe, $dish, [
        'name' => 'Risotto', 'yield' => '4', 'yield_unit' => BillOfMaterials::PORTIONS,
        'rows' => [['article_id' => $saffron->id, 'quantity' => '1'], ['article_id' => $this->fx['articles']['lok']->id, 'quantity' => '0,2']],
    ]);

    $cost = StructureBook::for($this->company)->cost($version, UnitCosts::at($this->company), CarbonImmutable::today());

    expect($cost->complete)->toBeFalse()->and($cost->rows[0]['line_cost_ore'])->toBeNull()->and($cost->batchCostOre)->toBe(300);
});

it('ignores an operation row when costing and exploding', function () {
    BomRow::create(['bill_of_materials_id' => $this->fx['butter']->id, 'component_article_id' => null, 'minutes' => 15, 'position' => 9]);

    $cost = StructureBook::for($this->company)->cost(StructureBook::for($this->company)->validOn($this->fx['articles']['vitloksmor']->id, $this->on), UnitCosts::at($this->company, $this->on), $this->on);

    expect($cost->batchCostOre)->toBe(10_840)->and($cost->rows)->toHaveCount(2);
});

it('refuses a portion recipe as an ingredient, and portions for a sub-recipe already used (review P2.1)', function () {
    $a = $this->fx['articles'];
    $writer = app(StructureWriter::class);

    // Pasta med svamp yields portions: it cannot go into another recipe.
    $plate = Kitchen::dish($this->company, $this->owner, 'M-4', 'Tallrik', '200,00');
    expect(fn () => $writer->save($this->company, $this->owner, StructureKind::Recipe, $plate, [
        'name' => 'Tallrik', 'yield' => '1', 'yield_unit' => BillOfMaterials::PORTIONS, 'rows' => [['article_id' => $a['dish']->id, 'quantity' => '1']],
    ]))->toThrow(ValidationException::class, 'utbyte i portioner');

    // Vitlökssmör is used in the pasta: a new version may not switch it to portions.
    expect(fn () => $writer->save($this->company, $this->owner, StructureKind::Recipe, $a['vitloksmor'], [
        'name' => 'Vitlökssmör', 'yield' => '10', 'yield_unit' => BillOfMaterials::PORTIONS, 'rows' => [['article_id' => $a['smor']->id, 'quantity' => '1']],
    ]))->toThrow(ValidationException::class, 'artikelns enhet');
});

it('refuses to switch a recipe back on when that closes a loop (review P2.2)', function () {
    $a = $this->fx['articles'];
    $writer = app(StructureWriter::class);

    // With Vitlökssmör off, smör is made from the stocked pasta, and the pasta from Vitlökssmör.
    $writer->setActive($this->company, $a['vitloksmor'], false);
    $writer->save($this->company, $this->owner, StructureKind::Recipe, $a['pasta'], [
        'name' => 'Färsk pasta', 'yield' => '1', 'yield_unit' => BillOfMaterials::UNIT, 'rows' => [['article_id' => $a['vitloksmor']->id, 'quantity' => '0,1']],
    ]);
    $writer->save($this->company, $this->owner, StructureKind::Recipe, $a['smor'], [
        'name' => 'Smör av pasta', 'yield' => '1', 'yield_unit' => BillOfMaterials::UNIT, 'rows' => [['article_id' => $a['pasta']->id, 'quantity' => '1']],
    ]);

    // Switching Vitlökssmör (made from smör) back on would close smör → pasta → Vitlökssmör → smör.
    expect(fn () => $writer->setActive($this->company, $a['vitloksmor'], true))->toThrow(ValidationException::class, 'ring')
        ->and(BillOfMaterials::query()->where('output_article_id', $a['vitloksmor']->id)->where('is_active', true)->exists())->toBeFalse();
});

it('explodes a given version or row set, and stops at a stocked component when asked (review P2.8)', function () {
    $a = $this->fx['articles'];
    $book = StructureBook::for($this->company);

    $whole = $book->explodeStructure($this->fx['pasta'], 10_000, $this->on);
    expect($whole)->toHaveKey($a['smor']->id)->not->toHaveKey($a['vitloksmor']->id);

    $stopped = $book->explodeStructure($this->fx['pasta'], 10_000, $this->on, stopAt: fn (int $articleId): bool => $articleId === $a['vitloksmor']->id);
    expect(StructureMath::round($stopped[$a['vitloksmor']->id]))->toBe(100)->and($stopped)->not->toHaveKey($a['smor']->id);

    $rows = $book->explodeStructure([$this->fx['pasta']->rows[1]], 2_000, $this->on, yieldMilli: 10_000);
    expect(array_map(StructureMath::round(...), $rows))->toBe([$a['champinjoner']->id => 267]);
});

it('lets the database change a saved version only by switching it, from Eloquent, the query builder and raw SQL (review P2.8)', function () {
    $id = $this->fx['pasta']->id;
    $rowId = $this->fx['pasta']->rows[0]->id;

    expect(fn () => $this->fx['pasta']->update(['name' => 'X']))->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::table('bills_of_materials')->where('id', $id)->update(['yield_milli' => 1])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::statement('UPDATE bom_rows SET quantity_milli = 1 WHERE id = ?', [$rowId])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::statement('UPDATE bills_of_materials SET valid_from = ? WHERE id = ?', ['2020-01-01', $id])))->toThrow(QueryException::class);

    DB::table('bills_of_materials')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
    expect(BillOfMaterials::query()->find($id)->is_active)->toBeFalse();
});
