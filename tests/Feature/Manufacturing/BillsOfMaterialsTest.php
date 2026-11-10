<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Kitchen\Actions\SaveRecipe;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Actions\SaveOperation;
use App\Modules\Manufacturing\Livewire\BillOfMaterialsPage;
use App\Modules\Manufacturing\Livewire\BillsOfMaterialsPage;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Inventory;
use Tests\Support\Manufacturing;

/*
 * Bills of materials with operations and their costing (ADR-071 §3, §6,
 * §12): the table of Tests\Support\Manufacturing costed to the öre, a
 * sub-assembly on its own bill of materials, the valuation cost before the
 * purchase price, overhead, versions, loops, the backdating guard, the
 * pages, and a fixed number of queries with 3 and with 30 products.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
});

it('costs the table to the öre: material, labour and the sum per unit', function () {
    $book = ManufacturingBook::for($this->company);
    $bom = $book->validOn($this->fx['articles']['table']->id);
    $costing = $book->cost($bom->rows, $bom->yield_milli);

    expect(array_map(fn (array $row): ?int => $row['material_ore'], $costing->rows))->toBe([234_900, 140_000, 1_520, 7_992, 0, 0, 0])
        ->and(array_map(fn (array $row): ?int => $row['labour_ore'], $costing->rows))->toBe([null, null, null, null, 22_500, 72_000, 26_000])
        ->and($costing->materialPerUnitOre)->toBe(384_412)
        ->and($costing->labourPerUnitOre)->toBe(120_500)
        ->and($costing->overheadPerUnitOre)->toBe(0)
        ->and($costing->perUnitOre())->toBe(504_912)
        ->and($costing->minutes())->toBe(150)
        ->and($costing->complete)->toBeTrue()
        ->and($costing->rows[3]['gross_milli'])->toBe(333)
        ->and($costing->rows[0]['source'])->toBe('purchase');
});

it('keeps what was typed beside the stock quantity, in the stock unit', function () {
    $rows = $this->fx['bom']->rows;

    expect($rows[3]->quantity_milli)->toBe(300)
        ->and($rows[3]->entered_quantity)->toBe('3')
        ->and($rows[3]->entered_unit)->toBe('dl')
        ->and($rows[3]->loss_bp)->toBe(1_000)
        ->and($rows[0]->entered_unit)->toBeNull()
        ->and($rows[4]->operation_id)->toBe($this->fx['operations']['cut']->id)
        ->and($rows[4]->minutes)->toBe(30)
        ->and($rows[4]->component_article_id)->toBeNull();
});

it('adds the overhead to material and labour, half up', function () {
    app(ModuleManager::class)->updateSettings(ManufacturingModule::KEY, $this->company, $this->owner, ['overhead_bp' => 1_500]);
    $book = ManufacturingBook::for($this->company);
    $bom = $book->validOn($this->fx['articles']['table']->id);
    $costing = $book->cost($bom->rows, $bom->yield_milli);

    // (384 412 + 120 500) × 15 % = 75 736,8 → 75 737.
    expect($costing->batchOverheadOre)->toBe(75_737)
        ->and($costing->perUnitOre())->toBe(580_649);
});

it('uses the valuation cost of a component on hand before its purchase price', function () {
    Inventory::receive($this->company, $this->owner, $this->fx['articles']['top'], $this->places['bin1'], '10', '1500,00', '2026-11-01');
    $book = ManufacturingBook::for($this->company);
    $bom = $book->validOn($this->fx['articles']['table']->id);
    $costing = $book->cost($bom->rows, $bom->yield_milli);

    expect($costing->rows[0]['source'])->toBe('valuation')
        ->and($costing->rows[0]['material_ore'])->toBe(243_000);
});

it('costs a sub-assembly from its own bill of materials, labour as labour', function () {
    $a = $this->fx['articles'];
    $frame = Inventory::article($this->company, $this->owner, 'RAM-1', 'Bordsram', ['purchase_price' => '', 'is_stock_item' => false]);
    $save = app(SaveBillOfMaterials::class);
    $save($this->owner, $this->company, $frame, ['name' => 'Bordsram', 'yield' => '1', 'rows' => [
        ['article_id' => $a['leg']->id, 'quantity' => '4'],
        ['operation_id' => $this->fx['operations']['assembly']->id, 'minutes' => '60'],
    ]]);
    $save($this->owner, $this->company, $a['table'], ['name' => 'Ekbord 180', 'yield' => '2', 'rows' => [
        ['article_id' => $frame->id, 'quantity' => '2'],
        ['article_id' => $a['top']->id, 'quantity' => '3,24'],
    ]]);

    $book = ManufacturingBook::for($this->company);
    $bom = $book->validOn($a['table']->id);
    $costing = $book->cost($bom->rows, $bom->yield_milli);

    // Frame: 140 000 material + 48 000 labour; two frames and two tops for a batch of two tables.
    expect($bom->version)->toBe(2)
        ->and($costing->rows[0]['source'])->toBe('structure')
        ->and($costing->rows[0]['material_ore'])->toBe(280_000)
        ->and($costing->rows[0]['labour_ore'])->toBe(96_000)
        ->and($costing->materialPerUnitOre)->toBe(374_900)
        ->and($costing->labourPerUnitOre)->toBe(48_000);
});

it('saves a change as a new version and keeps the old one', function () {
    Livewire::test(BillOfMaterialsPage::class, ['artikel' => $this->fx['articles']['table']->id])
        ->call('edit')
        ->assertSet('form.rows.3.quantity', '3')
        ->assertSet('form.rows.3.unit', 'dl')
        ->assertSet('form.rows.3.loss', '10')
        ->assertSet('form.rows.4.type', 'operation')
        ->set('form.rows.5.minutes', '120')
        ->call('save')
        ->assertDispatched('toast', message: 'Stycklistan är sparad som version 2, som gäller från 2026-11-10.');

    $versions = BillOfMaterials::query()->where('output_article_id', $this->fx['articles']['table']->id)->with('rows')->orderBy('version')->get();
    expect($versions->pluck('version')->all())->toBe([1, 2])
        ->and($versions[0]->rows[5]->minutes)->toBe(90)
        ->and($versions[1]->rows[5]->minutes)->toBe(120);

    $audit = AuditLog::query()->where('action', 'manufacturing.bom_saved')->latest('id')->first();
    expect($audit?->payload)->toMatchArray(['article_id' => $this->fx['articles']['table']->id, 'version' => 2, 'components' => 4, 'operations' => 3]);
});

it('creates a bill of materials from the page with a component and an operation', function () {
    $chest = Inventory::article($this->company, $this->owner, 'BYRA-80', 'Byrå 80', ['is_stock_item' => false]);

    Livewire::test(BillOfMaterialsPage::class)
        ->set('form.output_article_id', (string) $chest->id)
        ->set('form.name', 'Byrå 80')
        ->set('form.rows.0.article_id', (string) $this->fx['articles']['top']->id)
        ->set('form.rows.0.quantity', '250')
        ->set('form.rows.0.unit', 'dm²')
        ->set('form.rows.0.loss', '12,5')
        ->call('addRow', 'operation')
        ->set('form.rows.1.operation_id', (string) $this->fx['operations']['assembly']->id)
        ->set('form.rows.1.minutes', '120')
        ->call('save')
        ->assertRedirect(route('manufacturing.boms.show', $chest->id));

    $bom = BillOfMaterials::query()->where('output_article_id', $chest->id)->with('rows')->sole();
    // F-U4: 250 dm² is 2 500 milli-m², built in.
    expect($bom->kind)->toBe(StructureKind::Bom)
        ->and($bom->yield_unit)->toBe(BillOfMaterials::UNIT)
        ->and($bom->rows[0]->quantity_milli)->toBe(2_500)
        ->and($bom->rows[0]->loss_bp)->toBe(1_250)
        ->and($bom->rows[1]->minutes)->toBe(120);
});

it('refuses a loop, an unknown or archived operation, no components, zero minutes and a zero quantity', function (array $rows, string $field, string $message) {
    $a = $this->fx['articles'];
    $rows = array_map(fn (array $row): array => array_map(fn (mixed $value): mixed => match ($value) {
        'TOP' => $a['top']->id, 'TABLE' => $a['table']->id, 'LEG' => $a['leg']->id, 'CUT' => $this->fx['operations']['cut']->id, default => $value,
    }, $row), $rows);

    if ($message === 'arkiverat') {
        app(SaveOperation::class)->archive($this->owner, $this->company, $this->fx['operations']['cut'], true);
    }

    try {
        app(SaveBillOfMaterials::class)($this->owner, $this->company, $a['top'], ['name' => 'X', 'yield' => '1', 'rows' => $rows]);
        $this->fail('Saved.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field)
            ->and(implode(' ', $exception->errors()[$field]))->toContain($message);
    }
})->with([
    'a loop' => [[['article_id' => 'TABLE', 'quantity' => '1']], 'rows.0.article_id', 'ring'],
    'itself' => [[['article_id' => 'TOP', 'quantity' => '1']], 'rows.0.article_id', 'sin egen artikel'],
    'an unknown operation' => [[['article_id' => 'LEG', 'quantity' => '1'], ['operation_id' => 999_999, 'minutes' => '5']], 'rows.1.operation_id', 'Välj ett arbetssteg'],
    'only an operation' => [[['operation_id' => 'CUT', 'minutes' => '5']], 'rows', 'minst en komponent'],
    'an archived operation' => [[['article_id' => 'LEG', 'quantity' => '1'], ['operation_id' => 'CUT', 'minutes' => '5']], 'rows.1.operation_id', 'arkiverat'],
    'zero minutes' => [[['article_id' => 'LEG', 'quantity' => '1'], ['operation_id' => 'CUT', 'minutes' => '0']], 'rows.1.minutes', 'hela minuter'],
]);

it('refuses a recipe for an article with a bill of materials, and the other way round', function () {
    app(ModuleManager::class)->enable(KitchenModule::KEY, $this->company, $this->owner);

    expect(fn () => app(SaveRecipe::class)($this->owner, $this->company, $this->fx['articles']['table'], ['name' => 'X', 'yield' => '1', 'yield_unit' => 'UNIT', 'rows' => [['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'har redan en stycklista');

    // A bill of materials never shows up in Kök's book, nor a recipe in Tillverkning's.
    expect(StructureBook::for($this->company)->outputs())->toBe([])
        ->and(StructureBook::for($this->company, StructureKind::Bom)->outputs())->toBe([$this->fx['articles']['table']->id]);
});

it('needs a confirmation to date a bill of materials back, and audits it', function () {
    $input = ['name' => 'Ekbord 180', 'yield' => '1', 'valid_from' => '2026-11-01', 'rows' => [['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '4']]];

    expect(fn () => app(SaveBillOfMaterials::class)($this->owner, $this->company, $this->fx['articles']['table'], $input))
        ->toThrow(ValidationException::class, 'bakåt i tiden');

    $bom = app(SaveBillOfMaterials::class)($this->owner, $this->company, $this->fx['articles']['table'], [...$input, 'confirm_backdate' => true]);

    expect($bom->valid_from->toDateString())->toBe('2026-11-01')
        ->and(AuditLog::query()->where('action', 'manufacturing.bom_backdated')->exists())->toBeTrue();
});

it('switches a bill of materials off and on again', function () {
    Livewire::test(BillOfMaterialsPage::class, ['artikel' => $this->fx['articles']['table']->id])
        ->call('setActive', false)
        ->assertDispatched('toast', message: 'Stycklistan är inaktiverad.');

    expect(ManufacturingBook::for($this->company)->validOn($this->fx['articles']['table']->id))->toBeNull();

    Livewire::test(BillOfMaterialsPage::class, ['artikel' => $this->fx['articles']['table']->id])->call('setActive', true);

    expect(ManufacturingBook::for($this->company)->validOn($this->fx['articles']['table']->id))->not->toBeNull();
});

it('shows the list and the page with the cost split into material and labour', function () {
    $this->get(route('manufacturing.boms.index'))->assertOk()->assertSee('Ekbord 180')->assertSee("3\u{00A0}844,12")->assertSee("1\u{00A0}205,00")->assertSee("5\u{00A0}049,12");
    $this->get(route('manufacturing.boms.show', $this->fx['articles']['table']->id))->assertOk()
        ->assertSee('Kalkyl – inte lagervärdering')
        ->assertSee('3 dl')
        ->assertSee('Montering')
        ->assertSee('2 h 30 min för hela antalet');
});

it('answers 404 for an article without a bill of materials, and a recipe page for a bill of materials', function () {
    $this->get(route('manufacturing.boms.show', $this->fx['articles']['leg']->id))->assertNotFound();
    app(ModuleManager::class)->enable(KitchenModule::KEY, $this->company, $this->owner);
    $this->get(route('kitchen.recipes.show', $this->fx['articles']['table']->id))->assertNotFound();
});

it('lists bills of materials in a fixed number of queries', function () {
    $save = app(SaveBillOfMaterials::class);
    $add = function (int $from, int $to) use ($save): void {
        foreach (range($from, $to) as $index) {
            $product = Inventory::article($this->company, $this->owner, "P-{$index}", "Produkt {$index}", ['is_stock_item' => false]);
            $save($this->owner, $this->company, $product, ['name' => "Produkt {$index}", 'yield' => '1', 'rows' => [
                ['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '4'],
                ['operation_id' => $this->fx['operations']['assembly']->id, 'minutes' => '30'],
            ]]);
        }
    };
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(BillsOfMaterialsPage::class)->assertSee('Produkt 1');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $add(1, 3);
    // The first request also loads the user's current company once.
    $count();
    $few = $count();
    $add(4, 30);

    expect($count())->toBe($few);
});
