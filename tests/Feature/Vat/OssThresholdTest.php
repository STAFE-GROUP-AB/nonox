<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\ForeignSale;
use App\Domain\Vat\OssReport;
use App\Domain\Vat\OssThreshold;
use App\Domain\Vat\VatReturn;
use App\Livewire\Company\CompanyDetailsForm;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Livewire\Vat\EuReports;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Support\Currency\EcbRates;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\FakeEuroRates;

/*
 * The OSS threshold (ML 6 kap. 62-65 §§): under 99 680 kronor a year a sale
 * to a consumer in another EU country carries Swedish VAT unless the company
 * has elected taxation in the buyer's country. And the OSS return in euro
 * (ML 22 kap. 42 §) at the ECB rate for the quarter's last day.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->consumer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Hans Muster', 'country' => 'DE', 'payment_terms_days' => 30, 'foreign_sale' => 'EU_CONSUMER_OSS']);
});

function ossDraft(Customer $customer, string $price, string $date = '2026-03-10', array $extra = []): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id,
        'invoice_date' => $date,
        'foreign_sale' => ForeignSale::EuConsumerOss->value,
        'rows' => [['description' => 'Leverans', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500]],
        ...$extra,
    ]);
}

function ossInvoice(Customer $customer, string $price, string $date = '2026-03-10', array $extra = []): Invoice
{
    return app(IssueInvoice::class)(test()->owner, ossDraft($customer, $price, $date, $extra));
}

/** @return array<int, int> */
function ossLines(Invoice $invoice): array
{
    return Voucher::query()->with('rows.account')->findOrFail($invoice->voucher_id)->rows
        ->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();
}

it('keeps the threshold as one amount with its source', function () {
    expect(OssThreshold::AMOUNT_ORE)->toBe(9_968_000)
        ->and(OssThreshold::SOURCE)->toContain('6 kap. 62 §');
});

it('charges Swedish VAT under the threshold and reports the sale in the Swedish return', function () {
    $invoice = ossInvoice($this->consumer, '1 000');

    expect($invoice->swedish_vat_under_threshold)->toBeTrue()
        ->and($invoice->vat_ore->ore)->toBe(25_000)
        ->and(ossLines($invoice))->toHaveKey(2611)
        ->and(ossLines($invoice))->not->toHaveKey(2619)
        ->and(ossLines($invoice))->not->toHaveKey(3106)
        ->and(OssReport::for(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->rows)->toBe([]);

    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $return = VatReturn::calculate($year, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($return->boxes()[10])->toBe(250)
        ->and($return->boxes()[5])->toBe(1_000);
});

it('moves the sale that crosses the threshold and every later one to OSS', function () {
    $first = ossInvoice($this->consumer, '99 000');
    $crossing = ossInvoice($this->consumer, '1 000');
    $later = ossInvoice($this->consumer, '100');

    expect($first->swedish_vat_under_threshold)->toBeTrue()
        ->and($crossing->swedish_vat_under_threshold)->toBeFalse()
        ->and($crossing->vat_ore->ore)->toBe(19_000)
        ->and(ossLines($crossing))->toHaveKey(2619)
        ->and($later->swedish_vat_under_threshold)->toBeFalse()
        ->and(OssThreshold::sales([2026])[2026])->toBe(10_010_000);
});

it('uses OSS when last year\'s sales were over the threshold', function () {
    $old = ossDraft($this->consumer, '100 000', '2025-11-10');
    Invoice::query()->whereKey($old->id)->update(['status' => 'ISSUED', 'number' => 90001, 'issued_at' => now()]);

    $invoice = ossDraft($this->consumer, '500');

    expect(OssThreshold::sales([2025, 2026]))->toBe([2025 => 10_000_000, 2026 => 0])
        ->and($invoice->swedish_vat_under_threshold)->toBeFalse()
        ->and($invoice->rows->first()->vat_rate)->toBe(1900);
});

it('uses OSS from the day the company elected taxation in the buyer\'s country', function () {
    $this->company->forceFill(['oss_elected' => true, 'oss_elected_from' => '2026-03-01'])->save();

    expect(ossDraft($this->consumer, '100', '2026-02-27')->swedish_vat_under_threshold)->toBeTrue()
        ->and(ossDraft($this->consumer, '100', '2026-03-01')->swedish_vat_under_threshold)->toBeFalse();
});

it('credits an invoice under the threshold with Swedish VAT even after the threshold is crossed', function () {
    $invoice = ossInvoice($this->consumer, '1 000');
    ossInvoice($this->consumer, '99 000');

    $credit = app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice));

    expect($credit->swedish_vat_under_threshold)->toBeTrue()
        ->and($credit->vat_ore->ore)->toBe(-25_000)
        ->and(ossLines($credit))->toHaveKey(2611);
});

it('refuses to issue a draft whose VAT no longer follows the threshold', function () {
    $draft = ossDraft($this->consumer, '1 000');
    ossInvoice($this->consumer, '99 000');

    expect(fn () => app(IssueInvoice::class)($this->owner, $draft))->toThrow(ValidationException::class);

    $resaved = ossDraft($this->consumer, '1 000', extra: []);
    expect($resaved->swedish_vat_under_threshold)->toBeFalse();
});

it('warns in the invoice editor when the threshold is near or crossed', function () {
    ossInvoice($this->consumer, '85 000');

    $editor = Livewire::test(InvoiceEditor::class)
        ->set('customerId', (string) $this->consumer->id)
        ->set('invoiceDate', '2026-03-10')
        ->set('rows', [['article' => '', 'description' => 'Leverans', 'quantity' => '1', 'unit' => 'st', 'price' => '1000', 'discount' => '', 'vat_rate' => '2500', 'account' => '']]);

    $editor->assertSee('svensk moms')->assertSee('Snart nås tröskelvärdet');

    $editor->set('rows', [['article' => '', 'description' => 'Leverans', 'quantity' => '1', 'unit' => 'st', 'price' => '20000', 'discount' => '', 'vat_rate' => '2500', 'account' => '']])
        ->assertSee('går över tröskelvärdet');
});

it('saves the election on the company details page', function () {
    Livewire::test(CompanyDetailsForm::class, ['company' => $this->company])
        ->set('state.oss_elected', true)
        ->set('state.oss_elected_from', '2026-01-01')
        ->call('save')
        ->assertHasNoErrors();

    $company = $this->company->fresh();

    expect($company->oss_elected)->toBeTrue()
        ->and($company->oss_elected_from?->toDateString())->toBe('2026-01-01')
        ->and(AuditLog::query()->where('action', 'company.updated')->latest('id')->first()?->payload['after'])->toMatchArray(['oss_elected' => true, 'oss_elected_from' => '2026-01-01']);

    Livewire::test(CompanyDetailsForm::class, ['company' => $company])
        ->set('state.oss_elected', false)
        ->call('save');

    expect($company->fresh()->oss_elected_from)->toBeNull();
});

it('counts only the company\'s own sales towards the threshold', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::run($other, function () use ($otherOwner, $other) {
        $customer = app(SaveCustomer::class)($otherOwner, $other, ['name' => 'Anna Beispiel', 'country' => 'AT', 'payment_terms_days' => 30]);
        $draft = app(SaveDraftInvoice::class)($otherOwner, $other, [
            'customer_id' => $customer->id, 'invoice_date' => '2026-03-10', 'foreign_sale' => 'EU_CONSUMER_OSS',
            'rows' => [['description' => 'Leverans', 'quantity' => '1', 'price' => '200 000', 'vat_rate' => 2500]],
        ]);
        Invoice::query()->whereKey($draft->id)->update(['status' => 'ISSUED', 'number' => 90001, 'issued_at' => now()]);
    });

    expect(OssThreshold::sales([2026])[2026])->toBe(0)
        ->and(ossDraft($this->consumer, '100')->swedish_vat_under_threshold)->toBeTrue();
});

describe('the OSS return in euro', function () {
    beforeEach(function () {
        $this->company->forceFill(['oss_elected' => true])->save();
    });

    it('counts euro invoices in euro and converts others at the ECB rate for the quarter\'s last day', function () {
        Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-03-09', 'value' => 11.0]])]);
        FakeEuroRates::set('SEK', '2026-03-31', '11,0000');
        FakeEuroRates::set('USD', '2026-03-31', '1,1000');

        ossInvoice($this->consumer, '1 100');
        ossInvoice($this->consumer, '200', extra: ['currency' => 'EUR', 'exchange_rate' => '']);
        ossInvoice($this->consumer, '110', extra: ['currency' => 'USD', 'exchange_rate' => '10']);

        $report = OssReport::for(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));

        // 1 100 kr / 11 = 100 EUR, 200 EUR as invoiced, 110 USD / 1,10 = 100 EUR.
        expect($report->rows)->toHaveCount(1)
            ->and($report->rows[0]['net_eur'])->toBe(40_000)
            ->and($report->rows[0]['vat_eur'])->toBe(7_600)
            ->and($report->rows[0]['net'])->toBe(110_000 + 220_000 + 110_000)
            ->and(array_keys($report->euroRates))->toEqualCanonicalizing(['SEK', 'USD'])
            ->and($report->missingRates)->toBe([])
            ->and($report->csv())->toContain("DE;19,00;4400,00;836,00;400,00;76,00\r\n")
            ->and($report->csv())->toContain('Kurs;SEK per EUR;11,0000;ECB 2026-03-31');
    });

    it('takes the next published rate when none is published on the last day, and none before it exists', function () {
        ossInvoice($this->consumer, '1 100');

        $missing = OssReport::for(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));

        expect($missing->missingRates)->toBe(['SEK'])
            ->and($missing->rows[0]['net_eur'])->toBeNull()
            ->and($missing->totals()['net_eur'])->toBeNull();

        FakeEuroRates::set('SEK', '2026-04-01', '11,0000');

        $report = OssReport::for(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));

        expect($report->euroRates['SEK']->date)->toBe('2026-04-01')
            ->and($report->rows[0]['net_eur'])->toBe(10_000);
    });

    it('shows both currencies and downloads the basis', function () {
        FakeEuroRates::set('SEK', '2026-03-31', '11,0000');
        ossInvoice($this->consumer, '1 100');

        Livewire::test(EuReports::class)
            ->set('ossQuarter', '2026-K1')
            ->assertSee('Moms, EUR')
            ->assertSee('100,00')
            ->assertSee('1 EUR = 11,0000 SEK')
            ->call('downloadOss')
            ->assertFileDownloaded('oss-2026-K1.csv');

        expect(AuditLog::query()->where('action', 'vat.oss_report_downloaded')->exists())->toBeTrue();
    });

    it('never puts another company\'s OSS sales in the report', function () {
        ossInvoice($this->consumer, '1 100');
        [, $other] = Bookkeeping::company();

        $report = CurrentCompany::run($other, fn () => OssReport::for(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31')));

        expect($report->rows)->toBe([]);
    });
});

it('reads the ECB reference rate from the data portal, the next one published when the day has none', function () {
    Http::preventStrayRequests();
    Http::fake(['data-api.ecb.europa.eu/*' => Http::response(
        "KEY,FREQ,CURRENCY,CURRENCY_DENOM,EXR_TYPE,EXR_SUFFIX,TIME_PERIOD,OBS_VALUE,OBS_STATUS\r\n"
        ."EXR.D.SEK.EUR.SP00.A,D,SEK,EUR,SP00,A,2026-03-02,11.0475,A\r\n"
        ."EXR.D.SEK.EUR.SP00.A,D,SEK,EUR,SP00,A,2026-03-03,11.1,A\r\n",
    )]);

    $rate = (new EcbRates)->onOrAfter('sek', '2026-03-01');

    expect($rate?->date)->toBe('2026-03-02')
        ->and($rate?->micros)->toBe(11_047_500)
        ->and($rate?->format())->toBe('11,0475')
        ->and($rate?->toCents(110_475))->toBe(10_000)
        ->and((new EcbRates)->onOrAfter('EUR', '2026-03-01')?->micros)->toBe(1_000_000)
        ->and((new EcbRates)->onOrAfter('SEK', '2026-05-01'))->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'data/EXR/D.SEK.EUR.SP00.A') && str_contains($request->url(), 'startPeriod=2026-03-01') && str_contains($request->url(), 'format=csvdata'));
});

it('gives no ECB rate when the data portal cannot be reached', function () {
    Http::preventStrayRequests();
    Http::fake(['data-api.ecb.europa.eu/*' => Http::response('', 503)]);

    expect((new EcbRates)->onOrAfter('USD', '2026-03-31'))->toBeNull();
});
