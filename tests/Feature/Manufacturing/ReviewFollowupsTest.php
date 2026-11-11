<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Models\Article;
use App\Models\SalesDocumentRow;
use App\Models\Unit;
use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Actions\SaveRowStructure;
use App\Modules\Manufacturing\Livewire\RowStructuresPanel;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Inventory;
use Tests\Support\Manufacturing;

/*
 * Follow-ups from the M2 review (ADR-071 §3, §4, §6, §12): the stock unit
 * cannot change under a bill of materials or a row copy, an incomplete
 * sub-assembly falls back to its receipt or purchase price, nesting past
 * the limit is refused in Swedish, and the panel says when a row's article
 * no longer matches its copy.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
});

function changeStockUnit(Article $article, string $code): void
{
    $unit = (int) Unit::query()->where('code', $code)->value('id');

    app(SaveArticle::class)(test()->owner, test()->company, [
        'number' => $article->number,
        'name' => $article->name,
        'price' => '100',
        'vat_rate' => 2500,
        'stock_unit_id' => $unit,
        'purchase_unit_id' => $unit,
        'sales_unit_id' => $unit,
    ], $article->fresh());
}

it('refuses to change the stock unit of an article that is only a component of a row copy', function () {
    $quote = Manufacturing::document($this->company, $this->owner, [['BORD-180', '1', '12000,00', 'Ekbord 180']]);
    $key = $quote->rows[0]->line_key;
    $save = app(SaveRowStructure::class);
    $save->choose($this->owner, $quote, $key, $this->fx['articles']['table']);
    $glue = Inventory::article($this->company, $this->owner, 'LIM-1', 'Trälim');
    $save->update($this->owner, $quote, $key, ['yield' => '1', 'lines' => [['article_id' => $glue->id, 'quantity' => '1']]]);

    expect(fn () => changeStockUnit($glue, 'kg'))->toThrow(ValidationException::class, 'Lagerenheten kan inte ändras');
});

it('refuses to change the stock unit of the output of a bill of materials', function () {
    expect(fn () => changeStockUnit($this->fx['articles']['table'], 'kg'))->toThrow(ValidationException::class, 'Lagerenheten kan inte ändras');
});

it('refuses to change the stock unit of the output of a row copy', function () {
    $quote = Manufacturing::document($this->company, $this->owner, [['', '1', '100,00', 'Specialbord']]);
    $special = Inventory::article($this->company, $this->owner, 'SPEC-1', 'Specialbord', ['is_stock_item' => false]);
    RowStructure::create(['sales_document_id' => $quote->id, 'line_key' => $quote->rows[0]->line_key, 'output_article_id' => $special->id, 'name' => 'Specialbord', 'yield_milli' => 1000]);

    expect(fn () => changeStockUnit($special, 'kg'))->toThrow(ValidationException::class, 'Lagerenheten kan inte ändras');

    // An article in none of them still changes.
    $free = Inventory::article($this->company, $this->owner, 'FRI-1', 'Fri artikel', ['is_stock_item' => false]);
    changeStockUnit($free, 'kg');
    expect($free->fresh()->stock_unit_id)->toBe((int) Unit::query()->where('code', 'kg')->value('id'));
});

it('falls back to the receipt or purchase price when a sub-assembly\'s own bill of materials is incomplete', function () {
    $nameless = Inventory::article($this->company, $this->owner, 'OKAND-1', 'Okänd del', ['purchase_price' => '', 'is_stock_item' => false]);
    $frame = Inventory::article($this->company, $this->owner, 'RAM-1', 'Bordsram', ['purchase_price' => '900,00', 'is_stock_item' => false]);
    $save = app(SaveBillOfMaterials::class);
    $save($this->owner, $this->company, $frame, ['name' => 'Bordsram', 'yield' => '1', 'rows' => [['article_id' => $nameless->id, 'quantity' => '1']]]);
    $save($this->owner, $this->company, $this->fx['articles']['table'], ['name' => 'Ekbord', 'yield' => '1', 'rows' => [['article_id' => $frame->id, 'quantity' => '1']]]);

    $book = ManufacturingBook::for($this->company);
    $bom = $book->validOn($this->fx['articles']['table']->id);
    $costing = $book->cost($bom->rows, $bom->yield_milli);

    expect($costing->rows[0]['source'])->toBe('purchase')
        ->and($costing->rows[0]['material_ore'])->toBe(90_000)
        ->and($costing->complete)->toBeTrue();
});

it('refuses bills of materials nested deeper than the limit, in Swedish', function () {
    $save = app(SaveBillOfMaterials::class);
    $previous = $this->fx['articles']['leg'];

    foreach (range(1, ManufacturingBook::MAX_DEPTH) as $level) {
        $article = Inventory::article($this->company, $this->owner, "NIVA-{$level}", "Nivå {$level}", ['is_stock_item' => false]);
        $save($this->owner, $this->company, $article, ['name' => "Nivå {$level}", 'yield' => '1', 'rows' => [['article_id' => $previous->id, 'quantity' => '1']]]);
        $previous = $article;
    }

    // Twelve levels cost without trouble.
    $book = ManufacturingBook::for($this->company);
    $top = $book->validOn($previous->id);
    expect($book->cost($top->rows, $top->yield_milli)->complete)->toBeTrue();

    // A thirteenth on top is refused, and so is putting the chain under an existing bill of materials.
    $deeper = Inventory::article($this->company, $this->owner, 'NIVA-13', 'Nivå 13', ['is_stock_item' => false]);
    expect(fn () => $save($this->owner, $this->company, $deeper, ['name' => 'Nivå 13', 'yield' => '1', 'rows' => [['article_id' => $previous->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'för djup')
        ->and(fn () => $save($this->owner, $this->company, $this->fx['articles']['leg'], ['name' => 'Ben', 'yield' => '1', 'rows' => [['article_id' => $this->fx['articles']['screw']->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'för djup');

    $quote = Manufacturing::document($this->company, $this->owner, [['BORD-180', '1', '12000,00', 'Ekbord 180']]);
    $key = $quote->rows[0]->line_key;
    app(SaveRowStructure::class)->choose($this->owner, $quote, $key, $this->fx['articles']['table']);

    expect(fn () => app(SaveRowStructure::class)->update($this->owner, $quote, $key, ['yield' => '1', 'lines' => [['article_id' => $previous->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'för djup');
});

it('warns in the panel when a row\'s article no longer matches its copy', function () {
    $quote = Manufacturing::document($this->company, $this->owner, [['BORD-180', '1', '12000,00', 'Ekbord 180']]);
    $row = $quote->rows[0];
    app(SaveRowStructure::class)->choose($this->owner, $quote, $row->line_key, $this->fx['articles']['table']);

    Livewire::test(RowStructuresPanel::class, ['documentId' => $quote->id])->assertDontSee('stycklistan gör');

    SalesDocumentRow::query()->whereKey($row->id)->update(['article_id' => $this->fx['articles']['leg']->id]);

    Livewire::test(RowStructuresPanel::class, ['documentId' => $quote->id])
        ->assertSee('Raden gäller nu Bordsben ek, men stycklistan gör Ekbord 180.');
});
