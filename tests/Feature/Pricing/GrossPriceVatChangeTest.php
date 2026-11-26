<?php

declare(strict_types=1);

use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Pricing\PriceSource;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Models\Invoice;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PricingScenario;

/*
 * ADR-082 follow-up: a saved row priced from a list including VAT, saved again
 * with another VAT rate, gets the net price worked out from the stored gross
 * price with the new rate (rounded down), so the consumer never pays more than
 * the list price. A typed price and a row from a list excluding VAT are untouched.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

    $this->scenario = PricingScenario::build([
        'articles' => ['B1' => ['price_ore' => 80000], 'N1' => ['price_ore' => 10000]],
        'lists' => ['BRUTTO' => ['default' => true, 'prices_include_vat' => true, 'items' => [['article' => 'B1', 'price_minor' => 1001]]]],
        'customer' => [],
    ]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    $this->customer = $this->scenario->customer;
    $this->actingAs($this->owner);
});

function grossInvoice(array $row, ?Invoice $draft = null): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => test()->customer->id,
        'invoice_date' => '2026-03-15',
        'rows' => [['article' => 'B1', 'description' => 'B1', 'quantity' => '1', 'vat_rate' => 600, ...$row]],
    ], $draft);
}

/** The row as the editor sends it back: the stored price and source, a new VAT rate. */
function resend(Invoice $draft, int $vat, string $article = 'B1'): Invoice
{
    $stored = $draft->rows->first();

    return grossInvoice(['article' => $article, 'description' => $article, 'price' => number_format($stored->unit_price_ore->ore / 100, 2, ',', ''), 'price_source' => $stored->price_source->value, 'vat_rate' => $vat], $draft);
}

it('works out the net price of a saved invoice row from the stored gross price when the VAT rate changes', function () {
    $draft = grossInvoice([]);
    $row = $draft->rows->first();

    expect($row->unit_price_ore->ore)->toBe(944)
        ->and($row->price_explanation['gross_minor'])->toBe(1001);

    $again = resend($draft, 2500)->rows->first();

    expect($again->unit_price_ore->ore)->toBe(800)
        ->and($again->price_source)->toBe($row->price_source)
        ->and($again->price_explanation['gross_minor'])->toBe(1001)
        ->and($again->price_explanation['net_rounded_down'])->toBeTrue()
        ->and($again->price_explanation['list_id'])->toBe($row->price_explanation['list_id'])
        ->and($again->vat_rate)->toBe(2500);
});

it('derives from the stored gross price, not from a changed list', function () {
    $draft = grossInvoice([]);
    DB::table('price_list_items')->where('price_minor', 1001)->update(['price_minor' => 2001]);

    expect(resend($draft, 2500)->rows->first()->unit_price_ore->ore)->toBe(800);
});

it('keeps the price of a row whose VAT rate is unchanged', function () {
    $draft = grossInvoice([]);
    DB::table('price_list_items')->where('price_minor', 1001)->update(['price_minor' => 2001]);

    $again = resend($draft, 600)->rows->first();

    expect($again->unit_price_ore->ore)->toBe(944)
        ->and($again->price_explanation)->toBe($draft->rows->first()->price_explanation);
});

it('never touches a price typed by hand', function () {
    $draft = grossInvoice(['price' => '9,44', 'price_source' => 'MANUAL']);
    $again = resend($draft, 2500)->rows->first();

    expect($again->unit_price_ore->ore)->toBe(944)
        ->and($again->price_source)->toBe(PriceSource::Manual);
});

it('leaves a row from a list excluding VAT as it is when the VAT rate changes', function () {
    $draft = grossInvoice(['article' => 'N1', 'description' => 'N1', 'vat_rate' => 2500]);
    $price = $draft->rows->first()->unit_price_ore->ore;
    $again = resend($draft, 600, 'N1')->rows->first();

    expect($again->unit_price_ore->ore)->toBe($price)
        ->and($again->price_explanation)->not->toHaveKey('gross_minor');
});

it('works out the net price of a saved quote row from the stored gross price', function () {
    $save = fn (array $row, ?SalesDocument $document = null) => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-15',
        'rows' => [['article' => 'B1', 'description' => 'B1', 'quantity' => '1', 'vat_rate' => 600, ...$row]],
    ], $document);

    $quote = $save([]);
    expect($quote->rows->first()->unit_price_ore->ore)->toBe(944);

    $stored = $quote->rows->first();
    $again = $save(['price' => '9,44', 'price_source' => $stored->price_source->value, 'vat_rate' => 2500], $quote)->rows->first();

    expect($again->unit_price_ore->ore)->toBe(800)
        ->and($again->price_explanation['gross_minor'])->toBe(1001)
        ->and($again->price_explanation['net_rounded_down'])->toBeTrue();
});

it('keeps the gross price through quote to order and works it out again when the order\'s VAT rate changes', function () {
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-15',
        'rows' => [['article' => 'B1', 'description' => 'B1', 'quantity' => '1', 'vat_rate' => 600]],
    ]);
    $order = app(ProgressSalesDocument::class)->toOrder($this->owner, $quote);

    expect($order->rows->first()->unit_price_ore->ore)->toBe(944);

    $edited = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::ORDER, [
        'customer_id' => $this->customer->id,
        'document_date' => $order->document_date->toDateString(),
        'rows' => [['article' => 'B1', 'description' => 'B1', 'quantity' => '1', 'vat_rate' => 2500, 'price' => '9,44', 'price_source' => $order->rows->first()->price_source->value]],
    ], $order);

    expect($edited->rows->first()->unit_price_ore->ore)->toBe(800);

    $invoice = app(ProgressSalesDocument::class)->toInvoice($this->owner, $edited);

    expect($invoice->rows->first()->unit_price_ore->ore)->toBe(800);
});

it('tells the editor which loaded rows were priced from a list including VAT, with the rate as loaded and no amount', function () {
    $gross = grossInvoice([]);
    $net = grossInvoice(['article' => 'N1', 'description' => 'N1', 'vat_rate' => 2500]);
    $typed = grossInvoice(['price' => '9,44', 'price_source' => 'MANUAL']);

    $rows = fn (Invoice $draft) => Livewire::test(InvoiceEditor::class, ['invoice' => $draft])->get('rows');

    expect($rows($gross)[0]['price_gross_vat'])->toBe('600')
        ->and($rows($net)[0]['price_gross_vat'])->toBe('')
        ->and($rows($typed)[0]['price_gross_vat'])->toBe('');
});
