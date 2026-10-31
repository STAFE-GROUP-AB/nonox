<?php

declare(strict_types=1);

use App\Actions\Invoicing\CheckVatNumber;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\ForeignSale;
use App\Domain\Vat\EuSalesList;
use App\Domain\Vat\OssRates;
use App\Domain\Vat\OssReport;
use App\Domain\Vat\VatReturn;
use App\Livewire\Invoicing\Customers;
use App\Livewire\Vat\EuReports;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\VatNumberCheck;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_number' => 'SE556334368901', 'phone' => '08-123456'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $customer = fn (array $attributes) => app(SaveCustomer::class)($this->owner, $this->company, [...$attributes, 'payment_terms_days' => 30]);
    $this->danish = $customer(['name' => 'Eksempel ApS', 'vat_number' => 'DK31283522', 'country' => 'DK']);
    $this->finnish = $customer(['name' => 'Esimerkki Oy', 'vat_number' => 'FI01409351', 'country' => 'FI']);
    $this->consumer = $customer(['name' => 'Hans Muster', 'country' => 'DE']);
});

function euInvoice(Customer $customer, ForeignSale $sale, string $price = '1 000', string $date = '2026-03-10'): Invoice
{
    return app(IssueInvoice::class)(test()->owner, app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id,
        'invoice_date' => $date,
        'foreign_sale' => $sale->value,
        'rows' => [['description' => 'Leverans', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500]],
    ]));
}

/** @return array<int, int> */
function euLines(Invoice $invoice): array
{
    return Voucher::query()->with('rows.account')->findOrFail($invoice->voucher_id)->rows
        ->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();
}

function euMarch(): VatReturn
{
    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    return VatReturn::calculate($year, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));
}

it('books triangulation on 3107 without VAT and reports it in box 38', function () {
    $invoice = euInvoice($this->danish, ForeignSale::EuTriangular);

    expect($invoice->vat_ore->ore)->toBe(0)
        ->and(euLines($invoice))->toEqualCanonicalizing([1510 => 100_000, 3107 => -100_000])
        ->and(euMarch()->boxes()[38])->toBe(1_000);
});

it('charges the buyer country\'s VAT under OSS on 2619 and keeps it out of the Swedish return', function () {
    $invoice = euInvoice($this->consumer, ForeignSale::EuConsumerOss);

    expect(OssRates::standard('DE', CarbonImmutable::parse('2026-03-10')))->toBe(1900)
        ->and($invoice->vat_ore->ore)->toBe(19_000)
        ->and($invoice->total_ore->ore)->toBe(119_000)
        ->and(euLines($invoice))->toEqualCanonicalizing([1510 => 119_000, 3106 => -100_000, 2619 => -19_000]);

    $return = euMarch();

    expect($return->boxes()[10] ?? 0)->toBe(0)
        ->and($return->boxes()[49])->toBe(0)
        ->and($return->vatAccounts)->not->toHaveKey(2619);
});

it('does not need a VAT number for OSS but refuses a customer outside the EU', function () {
    $this->consumer->forceFill(['country' => 'US'])->save();

    expect(fn () => euInvoice($this->consumer->fresh(), ForeignSale::EuConsumerOss))->toThrow(ValidationException::class);
});

it('sums the OSS sales per country and rate for the quarter', function () {
    euInvoice($this->consumer, ForeignSale::EuConsumerOss);
    euInvoice($this->consumer, ForeignSale::EuConsumerOss, '500', '2026-02-01');

    $report = OssReport::for(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));

    expect($report->rows)->toBe([['country' => 'DE', 'rate' => 1900, 'net' => 150_000, 'vat' => 28_500]]);
});

it('lists EU sales per buyer in three columns and writes Skatteverket\'s file', function () {
    euInvoice($this->danish, ForeignSale::EuGoods, '21 700');
    euInvoice($this->danish, ForeignSale::EuTriangular, '13 600');
    euInvoice($this->finnish, ForeignSale::EuServices, '16 400,40');
    euInvoice($this->consumer, ForeignSale::EuConsumerOss);
    euInvoice($this->danish, ForeignSale::EuGoods, '999', '2026-04-01');

    $list = EuSalesList::for(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($list->periodCode())->toBe('2603')
        ->and($list->file($this->company, 'Anna Andersson', '08-123456', 'anna@example.test'))->toBe(
            "SKV574008;\r\n556334368901;2603;Anna Andersson;08-123456;anna@example.test\r\nDK31283522;21700;13600;;\r\nFI01409351;;;16400;\r\n"
        )
        ->and(EuSalesList::for(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->periodCode())->toBe('26-1');
});

it('reports a fully credited buyer with 0', function () {
    $invoice = euInvoice($this->danish, ForeignSale::EuGoods);
    app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice));
    $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00:00'));

    $list = EuSalesList::for(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-30'));

    expect($list->rows['DK31283522']['goods'])->toBe(0)
        ->and($list->file($this->company, 'A', '08-1', null))->toContain('DK31283522;0;;;');
});

it('checks a VAT number in VIES, keeps the answer and blocks the invoice when invalid', function () {
    Http::fake(['*/check-vat-number' => Http::sequence()
        ->push(['valid' => true, 'name' => 'EKSEMPEL APS', 'address' => '---', 'requestIdentifier' => 'WAPIAAAAA1'])
        ->push(['valid' => false, 'name' => '---', 'requestIdentifier' => 'WAPIAAAAA2'])]);

    $check = app(CheckVatNumber::class)($this->owner, $this->company, $this->danish);

    expect($check->valid)->toBeTrue()
        ->and($check->name)->toBe('EKSEMPEL APS')
        ->and($check->address)->toBeNull()
        ->and($check->request_identifier)->toBe('WAPIAAAAA1');

    Http::assertSent(fn ($request) => $request['countryCode'] === 'DK' && $request['vatNumber'] === '31283522' && $request['requesterNumber'] === '556334368901');

    euInvoice($this->danish, ForeignSale::EuGoods);

    app(CheckVatNumber::class)($this->owner, $this->company, $this->danish);

    expect(fn () => euInvoice($this->danish, ForeignSale::EuGoods))->toThrow(ValidationException::class)
        ->and(VatNumberCheck::query()->count())->toBe(2)
        ->and(fn () => VatNumberCheck::query()->first()?->update(['valid' => false]))->toThrow(LogicException::class);
});

it('explains when VIES does not answer', function () {
    Http::fake(['*' => Http::response(['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE']]], 200)]);

    expect(fn () => app(CheckVatNumber::class)($this->owner, $this->company, $this->danish))->toThrow(ValidationException::class);
});

it('checks from the customer form', function () {
    Http::fake(['*' => Http::response(['valid' => true, 'name' => 'EKSEMPEL APS', 'requestIdentifier' => 'WAPI1'])]);

    Livewire::test(Customers::class)
        ->call('edit', $this->danish->id)
        ->assertSee('Kontrollera i VIES')
        ->call('checkVatNumber')
        ->assertSee('Giltigt i VIES')
        ->assertSee('WAPI1');
});

it('shows both reports and downloads the file', function () {
    euInvoice($this->finnish, ForeignSale::EuServices);
    euInvoice($this->consumer, ForeignSale::EuConsumerOss);

    $this->get(route('vat.euReports'))->assertOk()->assertSee('Periodisk sammanställning');

    Livewire::test(EuReports::class)
        ->set('period', '2026-03')
        ->set('ossQuarter', '2026-K1')
        ->assertSee('FI01409351')
        ->assertSee('DE')
        ->set('phone', '08-123456')
        ->call('download')
        ->assertFileDownloaded('periodisksammanstallning'.preg_replace('/\D/', '', (string) $this->company->org_number).'2603.txt');
});
