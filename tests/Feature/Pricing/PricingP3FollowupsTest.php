<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Pricing\DocumentPricing;
use App\Domain\Pricing\PriceExplanation;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Livewire\Invoicing\SalesDocumentEditor;
use App\Livewire\Pricing\CustomerPrices;
use App\Models\Article;
use App\Models\Invoice;
use App\Models\PriceAgreementItem;
use App\Models\PriceListItem;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PricingScenario;

/*
 * ADR-082 P3 follow-ups: the browser works out the net price of a price
 * including VAT with the row's own VAT rate; previewing today's prices
 * changes nothing in the editor; pricing a document runs a fixed number of
 * queries; the explanation of a gross price is clear; and the customer's
 * price screen shows the price the editors give, to the öre.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

    $this->scenario = PricingScenario::build([
        'articles' => ['B1' => ['price_ore' => 80000], 'A1' => ['price_ore' => 10000]],
        'lists' => ['BRUTTO' => ['default' => true, 'prices_include_vat' => true, 'items' => [['article' => 'B1', 'price_minor' => 1001]]]],
        'customer' => [],
        'agreements' => ['PA' => ['valid_from' => '2026-01-01', 'items' => [['article' => 'A1', 'price_minor' => 9000]]]],
    ]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    $this->customer = $this->scenario->customer;
    $this->actingAs($this->owner);
});

function followupRow(array $row = []): array
{
    return ['article' => 'B1', 'description' => 'B1', 'quantity' => '1', 'vat_rate' => 2500, ...$row];
}

it('gives the browser the gross price so it can work out the net price with the row\'s own VAT rate', function () {
    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [followupRow()]]);
    $tier = Livewire::test(InvoiceEditor::class, ['invoice' => $draft])->instance()->priceTable()['B1']['tiers'][0];

    // 10,01 kr including VAT: the article's VAT rate (25 %) gives 8,00 kr. The tier carries the gross price for any other rate.
    expect($tier['gross'])->toBe(1001)
        ->and($tier['price'])->toBe(800)
        ->and($tier['price'])->toBe(DocumentPricing::consumerNet($tier['gross'], 2500));

    // A row at 12 % must get what the server gives it when saved, not the 25 % price.
    $saved = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [followupRow(['vat_rate' => 1200])]]);

    expect($saved->rows->first()->unit_price_ore->ore)->toBe(DocumentPricing::consumerNet($tier['gross'], 1200))
        ->and($saved->rows->first()->unit_price_ore->ore)->toBe(893)
        ->and($tier['price'])->not->toBe(893);
});

it('previewing today\'s prices for an expired quote changes no editor state', function () {
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-15',
        'until_date' => '2026-03-20',
        'rows' => [followupRow(['article' => 'A1', 'description' => 'A1'])],
    ]);
    PriceAgreementItem::query()->whereKey($this->scenario->items['PA#0'])->update(['price_minor' => 9500]);
    $this->travelTo(CarbonImmutable::parse('2026-04-01 10:00:00'));

    $editor = Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE, 'document' => $quote])->instance();
    $editor->keptUnitIds = [];
    $rows = $editor->rows;

    $editor->previewTodaysPrices();

    expect($editor->keptUnitIds)->toBe([])
        ->and($editor->rows)->toBe($rows)
        ->and($editor->todaysDiff)->toHaveCount(1);
});

it('prices a document in a fixed number of queries whatever the number of rows, in every editor', function () {
    $agreed = Article::query()->where('number', 'A1')->firstOrFail();
    $gross = Article::query()->where('number', 'B1')->firstOrFail();

    // Half the articles are priced by the agreement, half by the list including VAT.
    foreach (range(2, 120) as $i) {
        $copy = ($i % 2 === 0 ? $gross : $agreed)->replicate();
        $copy->number = "N{$i}";
        $copy->save();

        if ($i % 2 === 0) {
            PriceListItem::create(['price_list_id' => $this->scenario->lists['BRUTTO'], 'article_id' => $copy->id, 'min_quantity_milli' => 0, 'price_minor' => 1000 + $i]);
        }
    }

    $rows = fn (int $count): array => array_map(fn (int $i): array => followupRow(['article' => $i === 1 ? 'A1' : "N{$i}", 'description' => "Rad {$i}"]), range(1, $count));
    $reads = function (callable $step): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $step();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $invoice = fn (int $count): Invoice => app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => $rows($count)]);
    $quote = fn (int $count): SalesDocument => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $this->customer->id, 'document_date' => '2026-03-15', 'rows' => $rows($count)]);

    $short = $invoice(1);
    $long = $invoice(120);
    $shortQuote = $quote(1);
    $longQuote = $quote(120);

    $invoiceEditor = fn (Invoice $draft) => Livewire::test(InvoiceEditor::class, ['invoice' => $draft->fresh()]);
    $quoteEditor = fn (SalesDocument $document) => Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE, 'document' => $document->fresh()]);

    // A first run warms what is cached per request.
    $invoiceEditor($short)->instance()->priceTable();
    $quoteEditor($shortQuote)->instance()->priceTable();

    $steps = [
        'invoice price table' => fn (Invoice $draft) => fn () => $invoiceEditor($draft)->instance()->priceTable(),
        'invoice update prices' => fn (Invoice $draft) => function () use ($draft, $invoiceEditor): void {
            $invoiceEditor($draft)->call('previewPrices');
        },
        'quote price table' => fn (SalesDocument $document) => fn () => $quoteEditor($document)->instance()->priceTable(),
        'quote update prices' => fn (SalesDocument $document) => function () use ($document, $quoteEditor): void {
            $quoteEditor($document)->call('previewPrices');
        },
        'quote today\'s prices' => fn (SalesDocument $document) => function () use ($document, $quoteEditor): void {
            $quoteEditor($document)->call('previewTodaysPrices');
        },
    ];

    foreach ($steps as $name => $step) {
        $isInvoice = str_starts_with($name, 'invoice');
        $one = $reads($step($isInvoice ? $short : $shortQuote));
        $many = $reads($step($isInvoice ? $long : $longQuote));

        expect($many)->toBe($one, "{$name}: {$many} queries for 120 rows, {$one} for 1");
    }
});

it('explains a gross price with the list price including VAT and how the net unit price was worked out', function () {
    $net = PriceExplanation::describe(['source' => 'LIST', 'gross_minor' => 1001, 'net_rounded_down' => true], 'SEK', 'st');
    $plain = PriceExplanation::describe(['source' => 'LIST', 'gross_minor' => 1001], 'SEK', 'st');

    expect(implode(' ', $net['details']))->toContain('Listans pris är 10,01', 'inklusive moms', 'radens momssats', 'avrundat nedåt')
        ->and(implode(' ', $plain['details']))->toContain('Listans pris är 10,01', 'inklusive moms', 'à-priset utan moms');
});

it('shows the same net price on the customer\'s price screen as the editors give, to the öre', function () {
    // 10,01 kr including VAT at 25 % is 8,008 kr net: the editors round down to 8,00, half up would show 8,01.
    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [followupRow()]]);

    expect($draft->rows->first()->unit_price_ore->ore)->toBe(800);

    $cells = fn (string $number): array => customerTabCells($this->customer->id, $number);

    expect($cells('B1')['price'])->toBe('8,00 kr')
        ->and($cells('B1')['note'])->toBe('');
});

it('shows the agreed discount on the customer\'s price screen as included in the price', function () {
    $agreement = PricingScenario::build([
        'articles' => ['D1' => ['price_ore' => 10000]],
        'agreements' => ['DA' => ['valid_from' => '2026-01-01', 'items' => [['article' => 'D1', 'discount_bp' => 1000]]]],
        'customer' => [],
    ]);
    $this->actingAs($agreement->owner);

    // 100 kr with 10 % agreed: 90,00 kr, the row price an invoice gets, with the discount named as agreed.
    $cells = customerTabCells($agreement->customer->id, 'D1');

    expect($cells['price'])->toBe('90,00 kr')
        ->and($cells['note'])->toBe('efter −10 % avtalsrabatt');
});

it('combines a discount and a price including VAT on the customer\'s price screen, rounded down like the editors', function () {
    $both = PricingScenario::build([
        'articles' => ['G1' => ['price_ore' => 80000]],
        'lists' => ['BRUTTO' => ['default' => true, 'prices_include_vat' => true, 'items' => [['article' => 'G1', 'price_minor' => 1001]]]],
        'agreements' => ['GA' => ['valid_from' => '2026-01-01', 'items' => [['article' => 'G1', 'discount_bp' => 1000]]]],
        'customer' => [],
    ]);
    $this->actingAs($both->owner);

    // 10,01 kr including VAT less 10 %: 9,009 → 9,00 gross, then 7,20 net. Half up on either step would give 7,21.
    $draft = app(SaveDraftInvoice::class)($both->owner, $both->company, ['customer_id' => $both->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [followupRow(['article' => 'G1', 'description' => 'G1'])]]);
    $cells = customerTabCells($both->customer->id, 'G1');

    expect($draft->rows->first()->unit_price_ore->ore)->toBe(720)
        ->and($cells['price'])->toBe('7,20 kr')
        ->and($cells['note'])->toContain('−10 %');
});

it('keeps a saved row\'s price and source when its VAT rate is corrected and saved again', function () {
    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [followupRow()]]);
    $source = $draft->rows->first()->price_source;

    $again = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [
        followupRow(['vat_rate' => 1200, 'price' => '8,00', 'price_source' => $source->value]),
    ]], $draft);

    expect($again->rows->first()->unit_price_ore->ore)->toBe(800)
        ->and($again->rows->first()->price_source)->toBe($source);
});

/**
 * The customer tab's cells for an article: the price and the line under it.
 *
 * @return array{price: string, note: string}
 */
function customerTabCells(int $customerId, string $number): array
{
    $html = Livewire::test(CustomerPrices::class, ['customerId' => $customerId])->html();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

    foreach ((new DOMXPath($dom))->query('//tr[starts-with(@*[name()="wire:key"], "preview-")]') ?: [] as $row) {
        $cells = (new DOMXPath($dom))->query('td', $row);

        if (! str_starts_with(trim((string) $cells->item(0)?->textContent), $number)) {
            continue;
        }

        $note = (new DOMXPath($dom))->query('.//span[contains(@class, "block")]', $cells->item(2));
        $price = trim(preg_replace('/\s+/u', ' ', explode("\n", trim((string) $cells->item(2)?->textContent))[0]) ?? '');

        return ['price' => str_replace("\u{a0}", ' ', $price), 'note' => $note->length > 0 ? trim((string) $note->item(0)?->textContent) : ''];
    }

    throw new RuntimeException("No row for {$number}.");
}
