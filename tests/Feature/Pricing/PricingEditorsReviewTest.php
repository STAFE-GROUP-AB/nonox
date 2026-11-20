<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Pricing\PriceSource;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Livewire\Invoicing\SalesDocumentEditor;
use App\Models\Article;
use App\Models\Invoice;
use App\Models\PriceAgreementItem;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Crm;
use Tests\Support\PricingScenario;

/*
 * ADR-082 P3, review fixes: a changed currency, customer or date never
 * re-prices a row silently; an expired quote asks which prices apply; a
 * consumer never pays more than the shown price × quantity; an invoice from
 * a foreign-currency order carries the kronor VAT block; and the editor's
 * render, price table and save run a fixed number of queries.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

    $this->scenario = PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000], 'A2' => ['price_ore' => 20000]],
        'rate' => ['currency' => 'EUR', 'date' => '2026-03-13', 'micros' => 11_000_000],
        'customer' => [],
        'agreements' => ['PA' => ['valid_from' => '2026-01-01', 'items' => [['article' => 'A1', 'price_minor' => 9000]]]],
    ]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    $this->customer = $this->scenario->customer;
    $this->actingAs($this->owner);
});

/** @param  array<string, mixed>  $row */
function reviewRow(array $row = []): array
{
    return ['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500, ...$row];
}

/** @param  list<array<string, mixed>>  $rows */
function reviewQuote(array $rows, array $extra = [], ?SalesDocument $document = null): SalesDocument
{
    return app(SaveSalesDocument::class)(test()->owner, test()->company, SalesDocument::QUOTE, [
        'customer_id' => test()->customer->id,
        'document_date' => '2026-03-15',
        'rows' => $rows,
        ...$extra,
    ], $document);
}

it('does not keep a saved row\'s price as priced when the document\'s currency changed', function () {
    $quote = reviewQuote([reviewRow(['article' => 'A2'])], ['currency' => 'EUR']);
    expect($quote->rows->first()->unit_price_ore->ore)->toBe(1818);

    // The same numbers, now in kronor: not the converted price any more, so typed by hand.
    $again = reviewQuote([reviewRow(['article' => 'A2', 'price' => '18,18', 'price_source' => 'CONVERTED'])], ['currency' => 'SEK'], $quote);

    expect($again->currency)->toBe('SEK')
        ->and($again->rows->first()->unit_price_ore->ore)->toBe(20000);

    $kept = reviewQuote([reviewRow(['article' => 'A2', 'price' => '18,18'])], ['currency' => 'SEK'], $quote->fresh());
    expect($kept->rows->first()->price_source)->toBe(PriceSource::Manual)
        ->and($kept->rows->first()->unit_price_ore->ore)->toBe(1818);
});

it('keeps the shown price when the customer changes, as typed by hand when it differs from the new price', function () {
    $other = Crm::customer($this->company, $this->owner, 'Annan kund AB');
    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [reviewRow(), reviewRow(['article' => 'A2', 'description' => 'A2'])]]);

    expect($draft->rows->pluck('price_source')->all())->toBe([PriceSource::Agreement, PriceSource::Base]);

    // The editor clears the table mark on a new customer (afterContextChange): the rows come back without a source.
    $moved = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $other->id, 'invoice_date' => '2026-03-15', 'rows' => [
        reviewRow(['price' => '90,00']),
        reviewRow(['article' => 'A2', 'description' => 'A2', 'price' => '200,00']),
    ]], $draft);

    expect($moved->rows->pluck('unit_price_ore')->map->ore->all())->toBe([9000, 20000])
        ->and($moved->rows->pluck('price_source')->all())->toBe([PriceSource::Manual, PriceSource::Base]);
});

it('asks which prices apply when an expired quote becomes an order, and shows today\'s first', function () {
    $quote = reviewQuote([reviewRow(), reviewRow(['article' => 'A2', 'description' => 'A2 för hand', 'price' => '150', 'price_source' => 'MANUAL'])], ['until_date' => '2026-03-20']);
    PriceAgreementItem::query()->whereKey($this->scenario->items['PA#0'])->update(['price_minor' => 9500]);
    $this->travelTo(CarbonImmutable::parse('2026-04-01 10:00:00'));

    $editor = Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE, 'document' => $quote])
        ->call('toOrder')
        ->assertSet('expiredChoice', 'order')
        ->assertSee('Offerten gällde till 2026-03-20. Vilka priser ska gälla?')
        ->assertSee('Behåll offertens priser')
        ->call('previewTodaysPrices')
        ->assertSee('Dagens pris')
        ->assertSee('95,00')
        ->assertDontSee('A2 för hand');

    expect($editor->get('todaysDiff'))->toHaveCount(1);
    expect(SalesDocument::query()->where('kind', SalesDocument::ORDER)->count())->toBe(0);

    $editor->call('toOrder', 'today')->assertHasNoErrors();

    $order = SalesDocument::query()->where('kind', SalesDocument::ORDER)->firstOrFail();
    expect($order->rows->pluck('unit_price_ore')->map->ore->all())->toBe([9500, 15000])
        ->and($order->rows->pluck('price_source')->all())->toBe([PriceSource::Agreement, PriceSource::Manual]);
});

it('keeps the quote\'s prices for an expired quote by default', function () {
    $quote = reviewQuote([reviewRow()], ['until_date' => '2026-03-20']);
    PriceAgreementItem::query()->whereKey($this->scenario->items['PA#0'])->update(['price_minor' => 9500]);
    $this->travelTo(CarbonImmutable::parse('2026-04-01 10:00:00'));

    Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE, 'document' => $quote])
        ->call('toInvoice')
        ->assertSet('expiredChoice', 'invoice')
        ->call('toInvoice', 'keep');

    $invoice = Invoice::query()->latest('created_at')->firstOrFail();
    expect($invoice->rows->first()->unit_price_ore->ore)->toBe(9000)
        ->and($invoice->rows->first()->price_source)->toBe(PriceSource::Agreement);
});

it('writes quotes only in the offered currencies and shows a missing rate on the quote', function () {
    expect(fn () => reviewQuote([reviewRow()], ['currency' => 'JPY']))->toThrow(ValidationException::class);

    $quote = reviewQuote([reviewRow(['price' => '10', 'price_source' => 'MANUAL'])], ['currency' => 'NOK']);

    Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE, 'document' => $quote])
        ->call('toInvoice')
        ->assertHasErrors('exchange_rate')
        ->assertSee('Riksbanken har ingen kurs för NOK');
});

it('gives an invoice made from an order in euro the VAT in kronor on its PDF', function () {
    $order = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::ORDER, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-15',
        'currency' => 'EUR',
        'rows' => [reviewRow(['article' => 'A2', 'description' => 'A2', 'quantity' => '2'])],
    ]);

    $invoice = app(IssueInvoice::class)($this->owner, app(ProgressSalesDocument::class)->toInvoice($this->owner, $order));
    $html = InvoicePdf::html($invoice);

    expect($invoice->invoice_currency)->toBe('EUR')
        ->and($html)->toContain('I svenska kronor')
        ->and($html)->toContain('Moms');
});

it('renders the editor, builds its price table and saves in the same number of reads for 1 and 300 rows', function () {
    $template = Article::query()->where('number', 'A1')->firstOrFail();

    foreach (range(2, 300) as $i) {
        $copy = $template->replicate();
        $copy->number = "N{$i}";
        $copy->save();
    }

    $rows = fn (int $count): array => array_map(fn (int $i): array => reviewRow(['article' => $i === 1 ? 'A1' : "N{$i}", 'description' => "Rad {$i}"]), range(1, $count));
    $reads = function (callable $step): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $step();
        $queries = collect(DB::getQueryLog())->reject(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'insert'))->count();
        DB::disableQueryLog();

        return $queries;
    };

    $one = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => $rows(1)]);
    $many = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => $rows(300)]);

    $save = fn (Invoice $draft, int $count) => fn () => app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => $rows($count)], $draft->fresh());
    $render = fn (Invoice $draft) => fn () => Livewire::test(InvoiceEditor::class, ['invoice' => $draft->fresh()]);
    $table = fn (Invoice $draft) => function () use ($draft): void {
        Livewire::test(InvoiceEditor::class, ['invoice' => $draft->fresh()])->instance()->priceTable();
    };

    // A first run warms what is cached per request (the unit register, the company's settings).
    $render($one)();
    $table($one)();

    expect($reads($save($many, 300)))->toBe($reads($save($one, 1)))
        ->and($reads($render($many)))->toBe($reads($render($one)))
        ->and($reads($table($many)))->toBe($reads($table($one)));
});

it('never lets a consumer pay more than the shown price including VAT', function () {
    $consumer = PricingScenario::build([
        'articles' => ['B1' => ['price_ore' => 80000]],
        'lists' => ['BRUTTO' => ['default' => true, 'prices_include_vat' => true, 'items' => [['article' => 'B1', 'price_minor' => 1001]]]],
        'customer' => [],
    ]);
    $this->actingAs($consumer->owner);

    $draft = app(SaveDraftInvoice::class)($consumer->owner, $consumer->company, [
        'customer_id' => $consumer->customer->id,
        'invoice_date' => '2026-03-15',
        'rows' => [['article' => 'B1', 'description' => 'B1', 'quantity' => '3', 'vat_rate' => 2500]],
    ]);
    $row = $draft->rows->first();

    // 10,01 kr including VAT × 3 = 30,03 kr; rounding the net per unit half up (8,01 kr) would make it 30,04 kr.
    expect($row->unit_price_ore->ore)->toBe(800)
        ->and($row->price_explanation['net_rounded_down'])->toBeTrue()
        ->and($draft->net_ore->ore + $draft->vat_ore->ore)->toBeLessThanOrEqual(1001 * 3);
});
