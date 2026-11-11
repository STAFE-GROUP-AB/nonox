<?php

declare(strict_types=1);

use App\Actions\Invoicing\ProgressSalesDocument;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Livewire\Invoicing\SalesDocumentEditor;
use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Manufacturing\Actions\SaveRowStructure;
use App\Modules\Manufacturing\Livewire\RowStructuresPanel;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Modules\Manufacturing\Support\DocumentCosting;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use App\Modules\ModuleManager;
use App\Support\Branding\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Manufacturing;

/*
 * A quote or order row's own bill of materials and the costing on the
 * document (ADR-071 §4, §6): choosing copies the version into the row; the
 * copy is changed for the row only and the template is untouched; the copy
 * survives the document being saved again and follows the quote into the
 * order; "Spara som ny stycklista"; who may do what; never on the PDF; and
 * a fixed number of queries for the panel.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
    $this->quote = Manufacturing::document($this->company, $this->owner, [['BORD-180', '10', '12000,00', 'Ekbord 180'], ['', '1', '2500,00', 'Frakt']]);
    $this->tableRow = $this->quote->rows[0];
    $this->save = app(SaveRowStructure::class);
});

function documentCosting(SalesDocument $document): DocumentCosting
{
    $rows = SalesDocumentRow::query()->where('sales_document_id', $document->id)->orderBy('position')->get();
    $structures = RowStructure::query()->where('sales_document_id', $document->id)->with(['lines', 'outputArticle.stockUnit'])->get()->keyBy('line_key');

    return DocumentCosting::of($rows, $structures, ManufacturingBook::for(test()->company));
}

it('gives every row a key and keeps it when the document is saved again', function () {
    $keys = $this->quote->rows->pluck('line_key');

    expect($keys->filter()->count())->toBe(2)
        ->and($keys->unique()->count())->toBe(2);

    Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE, 'document' => $this->quote])
        ->assertSet('rows.0.line_key', $keys[0])
        ->set('rows.0.quantity', '12')
        ->call('save')
        ->assertDispatched('sales-document-saved');

    expect(SalesDocumentRow::query()->where('sales_document_id', $this->quote->id)->orderBy('position')->pluck('line_key')->all())->toBe($keys->all());
});

it('copies the bill of materials into the row and costs the document', function () {
    $structure = $this->save->choose($this->owner, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table']);

    expect($structure->lines)->toHaveCount(7)
        ->and($structure->bill_of_materials_id)->toBe($this->fx['bom']->id)
        ->and($structure->changed)->toBeFalse();

    $costing = documentCosting($this->quote);
    $row = $costing->rows[$this->tableRow->line_key];

    // Ten tables at 12 000,00 kr: cost 10 × 5 049,12 kr, contribution 69 508,80 kr, margin 57,9 %.
    expect($row['quantity_milli'])->toBe(10_000)
        ->and($row['cost_ore'])->toBe(5_049_120)
        ->and($row['net_ore'])->toBe(12_000_000)
        ->and($row['contribution_ore'])->toBe(6_950_880)
        ->and($row['margin_bp'])->toBe(5_792)
        ->and($costing->netOre)->toBe(12_000_000)
        ->and($costing->costOre)->toBe(5_049_120)
        ->and($costing->marginBp())->toBe(5_792)
        ->and($costing->rows)->toHaveCount(1);

    $audit = AuditLog::query()->where('action', 'manufacturing.row_structure_chosen')->latest('id')->first();
    expect($audit?->payload)->toMatchArray(['line_key' => $this->tableRow->line_key, 'bill_of_materials_id' => $this->fx['bom']->id, 'version' => 1, 'lines' => 7]);
});

it('changes the copy for the row and leaves the template untouched', function () {
    $this->save->choose($this->owner, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table']);
    $a = $this->fx['articles'];

    // Another top size, two extra legs, no lacquer, longer assembly.
    $structure = $this->save->update($this->owner, $this->quote, $this->tableRow->line_key, ['name' => 'Ekbord 180, bredare', 'yield' => '1', 'lines' => [
        ['article_id' => $a['top']->id, 'quantity' => '1,8'],
        ['article_id' => $a['leg']->id, 'quantity' => '6'],
        ['article_id' => $a['screw']->id, 'quantity' => '24'],
        ['operation_id' => $this->fx['operations']['cut']->id, 'minutes' => '30'],
        ['operation_id' => $this->fx['operations']['assembly']->id, 'minutes' => '120'],
    ]]);

    expect($structure->changed)->toBeTrue()
        ->and($structure->lines)->toHaveCount(5)
        ->and($structure->lines[0]->quantity_milli)->toBe(1_800);

    $template = BillOfMaterials::query()->with('rows')->findOrFail($this->fx['bom']->id);
    expect($template->rows)->toHaveCount(7)
        ->and($template->rows[0]->quantity_milli)->toBe(1_620)
        ->and(BillOfMaterials::query()->where('output_article_id', $a['table']->id)->count())->toBe(1);

    // 261 000 + 210 000 + 2 280 material, 22 500 + 96 000 labour = 591 780 per table.
    expect(documentCosting($this->quote)->rows[$this->tableRow->line_key]['cost_ore'])->toBe(5_917_800);
});

it('keeps the copy when the document is saved again, and follows the quote into the order', function () {
    $this->save->choose($this->owner, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table']);
    $this->save->update($this->owner, $this->quote, $this->tableRow->line_key, ['yield' => '1', 'lines' => [
        ['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '4'],
        ['operation_id' => $this->fx['operations']['assembly']->id, 'minutes' => '60'],
    ]]);

    Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE, 'document' => $this->quote])->call('save');
    expect(RowStructure::query()->where('sales_document_id', $this->quote->id)->count())->toBe(1);

    $order = app(ProgressSalesDocument::class)->toOrder($this->owner, $this->quote->fresh());
    $copy = RowStructure::query()->where('sales_document_id', $order->id)->with('lines')->sole();
    $orderRow = $order->rows()->orderBy('position')->first();

    expect($copy->line_key)->toBe($orderRow->line_key)
        ->and($copy->line_key)->toBe($this->tableRow->line_key)
        ->and($copy->changed)->toBeTrue()
        ->and($copy->lines->pluck('minutes')->all())->toBe([null, 60])
        // The quote keeps its own copy.
        ->and(RowStructure::query()->where('sales_document_id', $this->quote->id)->count())->toBe(1);
});

it('converts the row quantity to the output stock unit, and says when it cannot', function () {
    $quote = Manufacturing::document($this->company, $this->owner, [['BORD-180', '2', '12000,00', 'Ekbord 180']]);
    $row = $quote->rows[0];
    $this->save->choose($this->owner, $quote, $row->line_key, $this->fx['articles']['table']);
    expect(documentCosting($quote)->rows[$row->line_key]['quantity_milli'])->toBe(2_000);

    SalesDocumentRow::query()->whereKey($row->id)->update(['unit' => 'kg']);

    $line = documentCosting($quote)->rows[$row->line_key];
    expect($line['quantity_milli'])->toBeNull()
        ->and($line['cost_ore'])->toBeNull();
});

it('saves the copy as a new version of the bill of materials', function () {
    $this->save->choose($this->owner, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table']);
    $this->save->update($this->owner, $this->quote, $this->tableRow->line_key, ['yield' => '1', 'lines' => [
        ['article_id' => $this->fx['articles']['lacquer']->id, 'quantity' => '3', 'unit' => 'dl', 'loss' => '10'],
        ['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '6'],
    ]]);

    $bom = $this->save->saveAsBillOfMaterials($this->owner, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table'], ['name' => 'Ekbord 180 sex ben']);

    expect($bom->version)->toBe(2)
        ->and($bom->name)->toBe('Ekbord 180 sex ben')
        ->and($bom->rows[0]->entered_unit)->toBe('dl')
        ->and($bom->rows[0]->quantity_milli)->toBe(300)
        ->and($bom->rows[0]->loss_bp)->toBe(1_000)
        ->and($bom->rows[1]->quantity_milli)->toBe(6_000);
});

it('refuses a loop, an unknown row and a closed document', function () {
    $this->save->choose($this->owner, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table']);

    expect(fn () => $this->save->update($this->owner, $this->quote, $this->tableRow->line_key, ['yield' => '1', 'lines' => [['article_id' => $this->fx['articles']['table']->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'ring')
        ->and(fn () => $this->save->choose($this->owner, $this->quote, (string) Str::uuid(), $this->fx['articles']['table']))
        ->toThrow(ValidationException::class, 'Raden finns inte')
        ->and(fn () => $this->save->choose($this->owner, $this->quote, $this->tableRow->line_key, $this->fx['articles']['leg']))
        ->toThrow(ValidationException::class, 'ingen stycklista');

    app(ProgressSalesDocument::class)->cancel($this->owner, $this->quote);

    expect(fn () => $this->save->remove($this->owner, $this->quote->fresh(), $this->tableRow->line_key))->toThrow(AuthorizationException::class);
});

it('needs manufacturing:view to change a copy and manufacturing:manage to save it as a bill of materials', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $accountant = Bookkeeping::member($this->company, 'accountant');

    expect(fn () => $this->save->choose($bookkeeper, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table']))->toThrow(HttpException::class);

    $this->save->choose($accountant, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table']);

    expect(fn () => $this->save->saveAsBillOfMaterials($accountant, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table'], []))->toThrow(AuthorizationException::class);
});

it('shows the panel on the quote only with the module on, and never on the PDF', function () {
    $this->save->choose($this->owner, $this->quote, $this->tableRow->line_key, $this->fx['articles']['table']);

    $this->get(route('invoicing.quotes.show', ['document' => $this->quote->id]))->assertOk()
        ->assertSeeLivewire(RowStructuresPanel::class)
        ->assertSee('Stycklistor och kalkyl')
        ->assertSee('57,9 %');

    $quote = $this->quote->fresh(['rows', 'customer']);
    $pdf = view('invoicing.sales-pdf', [
        'document' => $quote,
        'company' => $this->company,
        'brand' => Brand::for($this->company),
        'totals' => InvoiceCalculator::totals($quote->rows->map(fn ($row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all()),
    ])->render();
    expect($pdf)->toContain('Ekbord 180')->not->toContain('Täckningsbidrag')->not->toContain('Montering')->not->toContain('Material');

    app(ModuleManager::class)->disable(ManufacturingModule::KEY, $this->company, $this->owner, true);
    $this->get(route('invoicing.quotes.show', ['document' => $this->quote->id]))->assertOk()->assertDontSeeLivewire(RowStructuresPanel::class);

    // The copy stays while the module is off.
    expect(RowStructure::query()->count())->toBe(1);
});

it('chooses, changes and removes the copy from the panel', function () {
    $key = $this->tableRow->line_key;

    Livewire::test(RowStructuresPanel::class, ['documentId' => $this->quote->id])
        ->assertSet("choice.{$key}", (string) $this->fx['articles']['table']->id)
        ->call('choose', $key)
        ->assertDispatched('toast', message: 'Stycklistan för Ekbord 180 är kopierad till raden. Ändringar här gäller bara den här raden.')
        ->assertSee('kopierad från version 1')
        ->call('edit', $key)
        ->assertSet('form.lines.3.unit', 'dl')
        ->call('removeLine', 3)
        ->call('addLine', 'operation')
        ->set('form.lines.6.operation_id', (string) $this->fx['operations']['assembly']->id)
        ->set('form.lines.6.minutes', '15')
        ->call('update')
        ->assertDispatched('toast', message: 'Radens stycklista är sparad. Mallen är oförändrad.')
        ->assertSee('ändrad för raden')
        ->call('remove', $key)
        ->assertDispatched('toast', message: 'Stycklistan är borttagen från raden.');

    expect(RowStructure::query()->count())->toBe(0)
        ->and(AuditLog::query()->whereIn('action', ['manufacturing.row_structure_chosen', 'manufacturing.row_structure_changed', 'manufacturing.row_structure_removed'])->count())->toBe(3);
});

it('shows the panel in a fixed number of queries', function () {
    $count = function (SalesDocument $document): int {
        Livewire::test(RowStructuresPanel::class, ['documentId' => $document->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(RowStructuresPanel::class, ['documentId' => $document->id])->assertSee('Stycklistor och kalkyl');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = Manufacturing::document($this->company, $this->owner, [['BORD-180', '1', '12000,00', 'Ekbord 180']]);
    $this->save->choose($this->owner, $small, $small->rows[0]->line_key, $this->fx['articles']['table']);

    $large = Manufacturing::document($this->company, $this->owner, array_fill(0, 12, ['BORD-180', '1', '12000,00', 'Ekbord 180']));

    foreach ($large->rows as $row) {
        $this->save->choose($this->owner, $large, $row->line_key, $this->fx['articles']['table']);
    }

    expect($count($large))->toBe($count($small));
});
