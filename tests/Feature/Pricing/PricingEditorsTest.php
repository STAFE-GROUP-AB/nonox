<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\SalesDocumentPdf;
use App\Domain\Invoicing\SalesRowInput;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceSource;
use App\Domain\Signing\QuoteFingerprint;
use App\Models\Article;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\PriceAgreementItem;
use App\Models\SalesDocument;
use App\Support\Branding\Brand;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\PricingScenario;

/*
 * ADR-082 P3: the price engine in SaveDraftInvoice, SaveSalesDocument and
 * ProgressSalesDocument. A row without a price gets the resolved one; a row
 * stores its source and explanation; a price typed by hand stays; a saved
 * row is never re-priced by saving it again; quote → order → invoice keeps
 * the quoted price and currency; an issued invoice never changes.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

    $this->scenario = PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000], 'A2' => ['price_ore' => 20000]],
        'rate' => ['currency' => 'EUR', 'date' => '2026-03-13', 'micros' => 11_000_000],
        'customer' => [],
        'agreements' => ['PA' => ['valid_from' => '2026-01-01', 'items' => [
            ['article' => 'A1', 'price_minor' => 9000],
            ['article' => 'A1', 'price_minor' => 8000, 'min_quantity_milli' => 10000],
            ['article' => 'A2', 'discount_bp' => 1000],
        ]]],
    ]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    $this->customer = $this->scenario->customer;
    $this->actingAs($this->owner);
});

/** @param  list<array<string, mixed>>  $rows */
function p3Draft(array $rows, ?Invoice $draft = null, array $extra = []): Invoice
{
    $scenario = test()->scenario;

    return app(SaveDraftInvoice::class)($scenario->owner, $scenario->company, [
        'customer_id' => $scenario->customer->id,
        'invoice_date' => '2026-03-15',
        'rows' => $rows,
        ...$extra,
    ], $draft);
}

/** @param  array<string, mixed>  $row */
function p3Row(array $row = []): array
{
    return ['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '1', 'vat_rate' => 2500, ...$row];
}

it('gives an article row without a price the customer\'s agreed price and stores why', function () {
    $draft = p3Draft([p3Row()]);
    $row = $draft->rows->first();

    expect($row->unit_price_ore->ore)->toBe(9000)
        ->and($row->price_source)->toBe(PriceSource::Agreement)
        ->and($row->price_explanation['agreement_id'])->toBe($this->scenario->agreements['PA'])
        ->and($row->net_ore->ore)->toBe(9000);
});

it('gives the whole quantity the price of the highest step it reaches', function () {
    $row = p3Draft([p3Row(['quantity' => '12'])])->rows->first();

    expect($row->unit_price_ore->ore)->toBe(8000)
        ->and($row->price_explanation['tier_min_milli'])->toBe(10000)
        ->and($row->net_ore->ore)->toBe(96000);
});

it('folds an agreed discount into the unit price and leaves the discount field for a typed one', function () {
    $row = p3Draft([p3Row(['article' => 'A2', 'description' => 'A2'])])->rows->first();

    expect($row->unit_price_ore->ore)->toBe(18000)
        ->and($row->discount_bp)->toBe(0)
        ->and($row->net_ore->ore)->toBe(18000)
        ->and($row->price_source)->toBe(PriceSource::Agreement)
        ->and($row->price_explanation['folded_discount_bp'])->toBe(1000)
        ->and($row->price_explanation)->not->toHaveKey('manual_discount_bp');
});

it('gives the same amount as the editor when a discount is typed on the folded price', function () {
    // The editor shows 180,00 (the agreed 10 % folded in); 15 % typed gives 153,00 there and on the saved row.
    $row = p3Draft([p3Row(['article' => 'A2', 'description' => 'A2', 'price' => '180,00', 'discount' => '15', 'price_source' => 'AGREEMENT'])])->rows->first();

    expect($row->unit_price_ore->ore)->toBe(18000)
        ->and($row->discount_bp)->toBe(1500)
        ->and($row->net_ore->ore)->toBe(15300)
        ->and($row->price_source)->toBe(PriceSource::Agreement)
        ->and($row->price_explanation['manual_discount_bp'])->toBe(1500);
});

it('folds the agreed discount into the unit price when the row gets a discount of its own', function () {
    $row = p3Draft([p3Row(['article' => 'A2', 'description' => 'A2', 'price' => '200,00', 'discount' => '5'])])->rows->first();

    expect($row->unit_price_ore->ore)->toBe(18000)
        ->and($row->discount_bp)->toBe(500)
        ->and($row->price_source)->toBe(PriceSource::Agreement)
        ->and($row->price_explanation['folded_discount_bp'])->toBe(1000)
        ->and($row->price_explanation['manual_discount_bp'])->toBe(500);
});

it('keeps the resolved source when the given price is the resolved one, and MANUAL otherwise', function () {
    $draft = p3Draft([
        p3Row(['price' => '90,00']),
        p3Row(['price' => '85,00']),
        p3Row(['price' => '90,00', 'price_source' => 'MANUAL']),
        ['description' => 'Frakt', 'quantity' => '1', 'price' => '150', 'vat_rate' => 2500],
    ]);

    expect($draft->rows->pluck('price_source')->all())->toBe([PriceSource::Agreement, PriceSource::Manual, PriceSource::Manual, null])
        ->and($draft->rows[1]->unit_price_ore->ore)->toBe(8500)
        ->and($draft->rows[3]->price_explanation)->toBeNull();
});

it('takes the server\'s price for a row the editor filled from its price table', function () {
    // A stale table (the agreement changed after the editor loaded) never wins over the server.
    $row = p3Draft([p3Row(['price' => '100,00', 'price_source' => 'BASE'])])->rows->first();

    expect($row->unit_price_ore->ore)->toBe(9000)
        ->and($row->price_source)->toBe(PriceSource::Agreement);
});

it('does not re-price a saved draft when it is saved again after the prices changed', function () {
    $draft = p3Draft([p3Row(['price_source' => 'AGREEMENT', 'price' => ''])]);
    $explanation = $draft->rows->first()->price_explanation;

    PriceAgreementItem::query()->whereKey($this->scenario->items['PA#0'])->update(['price_minor' => 7000]);

    $again = p3Draft([p3Row(['price' => '90,00', 'price_source' => 'AGREEMENT'])], $draft);

    expect($again->rows->first()->unit_price_ore->ore)->toBe(9000)
        ->and($again->rows->first()->price_source)->toBe(PriceSource::Agreement)
        ->and($again->rows->first()->price_explanation)->toBe($explanation);
});

it('asks for a price when the article has none in the currency', function () {
    expect(fn () => p3Draft([p3Row()], extra: ['currency' => 'NOK', 'exchange_rate' => '1,00']))
        ->toThrow(ValidationException::class, 'det finns inget pris för artikeln i NOK');
});

it('converts the base price into the invoice\'s currency with a warning when there is no list for it', function () {
    $this->customer->forceFill(['currency' => 'EUR'])->save();
    $row = p3Draft([p3Row(['article' => 'A2', 'description' => 'A2'])], extra: ['currency' => 'EUR'])->rows->first();

    expect($row->price_source)->toBe(PriceSource::Converted)
        ->and($row->foreign_unit_price_minor)->toBe(1818)
        ->and($row->price_explanation['warnings'])->toBe(['converted_currency'])
        ->and($row->price_explanation['rate_micros'])->toBe(11_000_000);
});

it('never prices a credit invoice: it copies the rows it credits', function () {
    $invoice = app(IssueInvoice::class)($this->owner, p3Draft([p3Row()]));
    $credit = app(CreditInvoice::class)($this->owner, $invoice);

    expect($credit->rows->first()->unit_price_ore->ore)->toBe(9000)
        ->and($credit->rows->first()->price_source)->toBeNull();
});

it('never re-prices an issued invoice, and the database refuses a change to its rows', function () {
    $invoice = app(IssueInvoice::class)($this->owner, p3Draft([p3Row()]));

    PriceAgreementItem::query()->whereKey($this->scenario->items['PA#0'])->update(['price_minor' => 1000]);

    expect(fn () => p3Draft([p3Row()], $invoice->fresh()))->toThrow(AuthorizationException::class);

    $row = InvoiceRow::query()->where('invoice_id', $invoice->id)->firstOrFail();
    expect($row->unit_price_ore->ore)->toBe(9000);

    expect(fn () => DB::transaction(fn () => InvoiceRow::query()->whereKey($row->id)->update(['price_source' => 'MANUAL'])))->toThrow(QueryException::class);
    expect(InvoiceRow::query()->findOrFail($row->id)->price_source)->toBe(PriceSource::Agreement);
});

it('keeps the quoted price and currency from quote to order to invoice', function () {
    $this->customer->forceFill(['currency' => 'EUR'])->save();

    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-15',
        'currency' => 'EUR',
        'rows' => [p3Row(['article' => 'A2', 'description' => 'A2', 'quantity' => '3'])],
    ]);

    expect($quote->currency)->toBe('EUR')
        ->and($quote->rows->first()->price_source)->toBe(PriceSource::Converted)
        ->and($quote->rows->first()->unit_price_ore->ore)->toBe(1818)
        // Another currency is not rounded to whole kronor.
        ->and($quote->rounding_ore->ore)->toBe(0)
        ->and($quote->total_ore->ore)->toBe(6818);

    // The rate moves; the customer accepted the quote's prices.
    app()->instance(RiksbankRates::class, new class extends RiksbankRates
    {
        public function __construct() {}

        public function rate(string $currency, string $date): ?ExchangeRate
        {
            return new ExchangeRate('EUR', $date, 12_000_000, 'riksbank');
        }
    });

    $order = app(ProgressSalesDocument::class)->toOrder($this->owner, $quote);
    $invoice = app(ProgressSalesDocument::class)->toInvoice($this->owner, $order);

    expect($order->currency)->toBe('EUR')
        ->and($order->rows->first()->unit_price_ore->ore)->toBe(1818)
        ->and($order->rows->first()->price_source)->toBe(PriceSource::Converted)
        ->and($order->rows->first()->article_id)->toBe($this->scenario->articles['A2'])
        ->and($invoice->invoice_currency)->toBe('EUR')
        ->and($invoice->rows->first()->foreign_unit_price_minor)->toBe(1818)
        ->and($invoice->rows->first()->price_source)->toBe(PriceSource::Converted)
        ->and($invoice->rows->first()->price_explanation)->toBe($quote->rows->first()->price_explanation);
});

it('keeps an agreed quote price on the order after the agreement changed', function () {
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-15',
        'rows' => [p3Row(), p3Row(['article' => 'A2', 'description' => 'A2', 'price' => '150', 'price_source' => 'MANUAL'])],
    ]);

    PriceAgreementItem::query()->whereKey($this->scenario->items['PA#0'])->update(['price_minor' => 5000]);
    $order = app(ProgressSalesDocument::class)->toOrder($this->owner, $quote);

    expect($order->rows->pluck('unit_price_ore')->map->ore->all())->toBe([9000, 15000])
        ->and($order->rows->pluck('price_source')->all())->toBe([PriceSource::Agreement, PriceSource::Manual]);
});

it('shows the currency of a quote in another currency on its PDF and in its fingerprint', function () {
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-15',
        'currency' => 'eur',
        'rows' => [p3Row(['price' => '12,50', 'price_source' => 'MANUAL'])],
    ]);
    $quote->load(['rows', 'customer']);

    $html = view('invoicing.sales-pdf', [
        'document' => $quote,
        'company' => $this->company,
        'brand' => Brand::for($this->company),
        'totals' => InvoiceCalculator::totals([['net' => 1250, 'vat_rate' => 2500]], roundToKronor: false),
    ])->render();

    expect($quote->currency)->toBe('EUR')
        ->and($html)->toContain('Valuta')
        ->and($html)->toContain('15,63'."\u{a0}".'EUR')
        ->and(SalesDocumentPdf::render($quote))->toStartWith('%PDF');

    $sek = $quote->replicate()->forceFill(['currency' => 'SEK']);
    $sek->id = $quote->id;
    expect(QuoteFingerprint::for($quote))->not->toBe(QuoteFingerprint::for($sek->setRelation('rows', $quote->rows)));
});

it('refuses a currency that is not three letters', function () {
    expect(fn () => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::ORDER, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-15',
        'currency' => 'EURO',
        'rows' => [p3Row()],
    ]))->toThrow(ValidationException::class);
});

it('prices a document of 1 or 300 rows in the same number of queries', function () {
    $template = Article::query()->findOrFail($this->scenario->articles['A1']);
    $numbers = ['A1'];

    foreach (range(2, 300) as $i) {
        $copy = $template->replicate();
        $copy->number = "N{$i}";
        $copy->save();
        $numbers[] = "N{$i}";
    }

    $context = new PriceContext($this->company->id, '2026-03-15', 'SEK', $this->customer->id);
    $count = function (array $numbers) use ($context): int {
        $rows = array_map(fn (string $number): array => p3Row(['article' => $number, 'quantity' => '12']), $numbers);
        DB::flushQueryLog();
        DB::enableQueryLog();
        SalesRowInput::parse($rows, customer: $this->customer, pricing: $context);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(['A1']))->toBe($count($numbers));
});
