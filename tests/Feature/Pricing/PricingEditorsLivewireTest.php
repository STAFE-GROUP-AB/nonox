<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceExplanation;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\PriceTable;
use App\Livewire\Invoicing\AgreementEditor;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Livewire\Invoicing\SalesDocumentEditor;
use App\Models\Article;
use App\Models\Invoice;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\SalesDocument;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\PricingScenario;

/*
 * ADR-082 P3: the editors. The price table the browser gets, the row's
 * source, "Varför detta pris?" built from ids with names looked up when
 * shown, "Uppdatera priser" showing a diff before anything changes, the
 * currency of quotes and orders, and another company's prices never seen.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

    $this->scenario = PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000], 'A2' => ['price_ore' => 20000]],
        'lists' => ['KAMP' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-03-01', 'valid_to' => '2026-03-31', 'items' => [['article' => 'A2', 'price_minor' => 15000]]]],
        'customer' => [],
        'agreements' => ['PA' => ['valid_from' => '2026-01-01', 'items' => [
            ['article' => 'A1', 'price_minor' => 9000],
            ['article' => 'A1', 'price_minor' => 8000, 'min_quantity_milli' => 10000],
        ]]],
    ]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    $this->customer = $this->scenario->customer;
    $this->actingAs($this->owner);
});

function p3Context(?int $customerId = null): PriceContext
{
    return new PriceContext(test()->company->id, '2026-03-15', 'SEK', $customerId ?? test()->customer->id);
}

/** @param  list<array<string, mixed>>  $rows */
function p3EditorDraft(array $rows): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => test()->customer->id,
        'invoice_date' => '2026-03-15',
        'rows' => $rows,
    ]);
}

it('gives the browser a price table with the customer\'s prices and their quantity steps', function () {
    $table = app(PriceTable::class)->for(p3Context(), Article::query()->orderBy('number')->get());

    expect(array_keys($table))->toBe(['A1', 'A2'])
        ->and($table['A1']['tiers'])->toBe([
            ['min' => 1000, 'price' => 9000, 'source' => 'AGREEMENT', 'warnings' => []],
            ['min' => 10000, 'price' => 8000, 'source' => 'AGREEMENT', 'warnings' => []],
        ])
        ->and($table['A2']['tiers'][0]['price'])->toBe(15000)
        ->and($table['A2']['tiers'][0]['source'])->toBe('CAMPAIGN');

    // Without prices:view a tier says nothing about where its price comes from.
    expect(app(PriceTable::class)->for(p3Context(), Article::query()->where('number', 'A1')->get(), withSources: false)['A1']['tiers'][0]['source'])->toBe('');

    // The editor's table holds the rows' articles and one just picked, never the whole catalogue.
    $editor = Livewire::test(InvoiceEditor::class)->set('customerId', (string) $this->customer->id);
    expect($editor->instance()->priceTable())->toBe([])
        ->and(array_keys($editor->instance()->priceTable(['A1'])))->toBe(['A1'])
        ->and($editor->instance()->priceTable(['A1'])['A1']['tiers'][0]['price'])->toBe(9000);
});

it('builds the price table for 1 or 300 articles in the same number of queries', function () {
    $template = Article::query()->where('number', 'A1')->firstOrFail();

    foreach (range(2, 300) as $i) {
        $copy = $template->replicate();
        $copy->number = "N{$i}";
        $copy->save();
    }

    $count = function ($articles): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PriceTable::class)->for(p3Context(), $articles);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(Article::query()->where('number', 'A1')->get()))->toBe($count(Article::query()->get()));
});

it('loads a saved row with its source and explains it with the agreement\'s name', function () {
    $draft = p3EditorDraft([
        ['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '12', 'vat_rate' => 2500],
        ['article' => 'A2', 'description' => 'Artikel A2', 'quantity' => '1', 'vat_rate' => 2500],
    ]);

    Livewire::test(InvoiceEditor::class, ['invoice' => $draft])
        ->assertSet('rows.0.article', 'A1')
        ->assertSet('rows.0.price_source', 'AGREEMENT')
        ->assertSet('rows.1.price_source', 'CAMPAIGN')
        ->assertSeeHtml('data-price-marker')
        ->call('explainPrice', 0)
        ->assertSee('Varför detta pris?')
        ->assertSee('Prisavtal 1, Avtal PA')
        ->assertSee('Mängdpris från 10 st.')
        ->call('explainPrice', 1)
        ->assertSee('Kampanj Lista KAMP, gäller till 2026-03-31')
        ->assertSee('Andra priser som prövades')
        ->assertSee('Grundpris: 200,00 kr – en kampanj var lägre')
        ->call('closePriceExplanation')
        ->assertDontSee('Andra priser som prövades');
});

it('explains a row typed by hand without naming any list', function () {
    $draft = p3EditorDraft([['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'price' => '75', 'vat_rate' => 2500]]);

    Livewire::test(InvoiceEditor::class, ['invoice' => $draft])
        ->assertSet('rows.0.price_source', 'MANUAL')
        ->call('explainPrice', 0)
        ->assertSee('Priset är skrivet för hand och ändras inte av prislistorna.');
});

it('explains an unsaved row from the price engine', function () {
    Livewire::test(InvoiceEditor::class)
        ->set('customerId', (string) $this->customer->id)
        ->set('rows', [['article' => 'A2', 'description' => 'A2', 'quantity' => '1', 'unit_id' => '', 'price' => '150,00', 'discount' => '', 'vat_rate' => '2500', 'account' => '', 'price_source' => 'CAMPAIGN', 'price_explanation' => '', 'price_warnings' => '']])
        ->call('explainPrice', 0)
        ->assertSee('Kampanj Lista KAMP, gäller till 2026-03-31');
});

it('shows what "Uppdatera priser" would change first, and changes only rows not typed by hand', function () {
    $draft = p3EditorDraft([
        ['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500],
        ['article' => 'A2', 'description' => 'A2 för hand', 'quantity' => '1', 'price' => '199', 'vat_rate' => 2500],
    ]);

    PriceAgreementItem::query()->whereKey($this->scenario->items['PA#0'])->update(['price_minor' => 8500]);

    $editor = Livewire::test(InvoiceEditor::class, ['invoice' => $draft])
        ->call('previewPrices')
        ->assertSee('Dagens priser')
        ->assertSee('90,00')
        ->assertSee('85,00')
        ->assertDontSee('A2 för hand')
        ->assertSet('rows.0.price', '90,00');

    expect($editor->get('priceDiff'))->toHaveCount(1);

    $editor->call('applyPrices')
        ->assertSet('priceDiff', null)
        ->assertSet('rows.0.price', '85,00')
        ->assertSet('rows.1.price', '199,00')
        ->assertDispatched('toast')
        ->call('save')
        ->assertHasNoErrors();

    $rows = $draft->fresh()->rows;
    expect($rows->pluck('unit_price_ore')->map->ore->all())->toBe([8500, 19900])
        ->and($rows->pluck('price_source')->all())->toBe([PriceSource::Agreement, PriceSource::Manual]);
});

it('says so when every price already matches', function () {
    $draft = p3EditorDraft([['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500]]);

    Livewire::test(InvoiceEditor::class, ['invoice' => $draft])
        ->call('previewPrices')
        ->assertSet('priceDiff', null)
        ->assertDispatched('toast', message: 'Alla priser stämmer redan med dagens priser.');
});

it('cancels "Uppdatera priser" without changing a row', function () {
    $draft = p3EditorDraft([['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500]]);
    PriceAgreementItem::query()->whereKey($this->scenario->items['PA#0'])->update(['price_minor' => 8500]);

    Livewire::test(InvoiceEditor::class, ['invoice' => $draft])
        ->call('previewPrices')
        ->call('cancelPriceUpdate')
        ->assertSet('priceDiff', null)
        ->assertSet('rows.0.price', '90,00');
});

it('writes a quote in the customer\'s currency and keeps it', function () {
    $this->customer->forceFill(['currency' => 'EUR'])->save();

    Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE])
        ->set('customerId', (string) $this->customer->id)
        ->assertSet('currency', 'EUR')
        ->set('rows', [['article' => '', 'description' => 'Konsult', 'quantity' => '2', 'unit_id' => '', 'price' => '100', 'discount' => '', 'vat_rate' => '2500', 'account' => '', 'price_source' => '', 'price_explanation' => '', 'price_warnings' => '']])
        ->call('save')
        ->assertHasNoErrors();

    $quote = SalesDocument::query()->latest('created_at')->firstOrFail();
    expect($quote->currency)->toBe('EUR')
        ->and($quote->total_ore->ore)->toBe(25000);

    Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE, 'document' => $quote])
        ->assertSet('currency', 'EUR')
        ->assertSee('Priserna skrivs i EUR.');
});

it('does not price the rows of a recurring invoice yet', function () {
    expect(Livewire::test(AgreementEditor::class)->instance()->priceTable())->toBe([]);
});

it('never shows another company\'s lists or agreements', function () {
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $other = PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000]],
        'lists' => ['HEMLIG' => ['default' => true, 'items' => [['article' => 'A1', 'price_minor' => 100]]]],
        'customer' => [],
        'agreements' => ['X' => ['valid_from' => '2026-01-01', 'items' => [['article' => 'A1', 'price_minor' => 200]]]],
    ], $otherOwner, $otherCompany);

    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    // The other company's list and agreement ids, as a tampered browser might send them.
    $foreign = (string) json_encode(['source' => 'AGREEMENT', 'agreement_id' => $other->agreements['X'], 'considered' => [['source' => 'CUSTOMER_LIST', 'list_id' => $other->lists['HEMLIG'], 'reason' => 'agreement_wins']]]);

    Livewire::test(InvoiceEditor::class)
        ->set('customerId', (string) $other->customer->id)
        ->set('rows', [['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'unit_id' => '', 'price' => '90', 'discount' => '', 'vat_rate' => '2500', 'account' => '', 'price_source' => 'AGREEMENT', 'price_explanation' => $foreign, 'price_warnings' => '']])
        ->call('explainPrice', 0)
        ->assertDontSee('HEMLIG')
        ->assertDontSee('Avtal X');

    $table = app(PriceTable::class)->for(new PriceContext($this->company->id, '2026-03-15', 'SEK'), Article::query()->get());
    expect($table['A1']['tiers'][0]['price'])->toBe(10000)
        ->and(PriceExplanation::describe(json_decode($foreign, true), 'SEK')['summary'])->not->toContain('Avtal X')
        ->and(PriceList::query()->whereKey($other->lists['HEMLIG'])->exists())->toBeFalse()
        ->and(PriceAgreement::query()->whereKey($other->agreements['X'])->exists())->toBeFalse();

    // A document of this company never takes the other company's customer.
    expect(fn () => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $other->customer->id,
        'document_date' => '2026-03-15',
        'rows' => [['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500]],
    ]))->toThrow(ValidationException::class);
});
