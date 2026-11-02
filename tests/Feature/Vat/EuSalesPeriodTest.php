<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\ForeignSale;
use App\Domain\Vat\EuSalesList;
use App\Livewire\Company\CompanyDetailsForm;
use App\Livewire\Vat\EuReports;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Periodisk sammanställning: which period a sale belongs to and which
 * periods may be used (skatteförfarandelagen (2011:1244) 35 kap. 2–4 och
 * 7 §§, mervärdesskattelagen (2023:200) 7 kap. 29–30 §§), and the
 * reporter's VAT number in the file (Skatteverket, "Lämna periodisk
 * sammanställning med filöverföring", read 2026-11-02). Enligt granskning
 * 2026-11-02, juristgranskning återstår (OSAKERT-MOMS-PS, #65).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_number' => 'SE556334368901', 'phone' => '08-123456'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $customer = fn (array $attributes) => app(SaveCustomer::class)($this->owner, $this->company, ['address_line1' => 'Testgatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm', ...$attributes, 'payment_terms_days' => 30]);
    $this->danish = $customer(['name' => 'Eksempel ApS', 'vat_number' => 'DK31283522', 'country' => 'DK']);
    $this->finnish = $customer(['name' => 'Esimerkki Oy', 'vat_number' => 'FI01409351', 'country' => 'FI']);
});

function psInvoice(Customer $customer, ForeignSale $sale, string $price, string $date, ?string $delivery = null): Invoice
{
    return app(IssueInvoice::class)(test()->owner, app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id,
        'invoice_date' => $date,
        'delivery_date' => $delivery,
        'foreign_sale' => $sale->value,
        'rows' => [['description' => 'Leverans', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500]],
    ]));
}

function psList(string $start, string $end): EuSalesList
{
    return EuSalesList::for(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
}

it('reports a service in the period it was performed, not the period it was invoiced (ML 7 kap. 30 §)', function () {
    // Performed 20 March, invoiced 1 April: it belongs to March and to quarter 1.
    psInvoice($this->finnish, ForeignSale::EuServices, '5 000', '2026-04-01', '2026-03-20');

    expect(psList('2026-03-01', '2026-03-31')->rows)->toBe(['FI01409351' => ['goods' => 0, 'triangular' => 0, 'services' => 500_000]])
        ->and(psList('2026-01-01', '2026-03-31')->rows)->toHaveKey('FI01409351')
        ->and(psList('2026-04-01', '2026-04-30')->rows)->toBe([]);
});

it('reports a service without a delivery date on its invoice date', function () {
    psInvoice($this->finnish, ForeignSale::EuServices, '5 000', '2026-04-01');

    expect(psList('2026-03-01', '2026-03-31')->rows)->toBe([])
        ->and(psList('2026-04-01', '2026-04-30')->rows['FI01409351']['services'])->toBe(500_000);
});

it('reports goods on the invoice date even when the delivery date is earlier (ML 7 kap. 29 §)', function () {
    psInvoice($this->danish, ForeignSale::EuGoods, '2 000', '2026-04-01', '2026-03-25');

    expect(psList('2026-03-01', '2026-03-31')->rows)->toBe([])
        ->and(psList('2026-04-01', '2026-04-30')->rows['DK31283522']['goods'])->toBe(200_000);
});

it('reports a credit of a service on the credit invoice\'s date', function () {
    $invoice = psInvoice($this->finnish, ForeignSale::EuServices, '5 000', '2026-03-31', '2026-03-20');
    app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice));

    // The credit is dated today, 2 April: March keeps +5 000, April gets -5 000.
    expect(psList('2026-03-01', '2026-03-31')->rows['FI01409351']['services'])->toBe(500_000)
        ->and(psList('2026-04-01', '2026-04-30')->rows['FI01409351']['services'])->toBe(-500_000);
});

it('refuses a quarter for goods without Skatteverket\'s decision (SFL 35 kap. 3–4 §§)', function () {
    psInvoice($this->danish, ForeignSale::EuGoods, '21 700', '2026-03-10');

    $quarter = psList('2026-01-01', '2026-03-31');

    expect($quarter->hasGoods)->toBeTrue()
        ->and($quarter->periodProblem($this->company))->toContain('redovisas per månad')
        ->and(psList('2026-03-01', '2026-03-31')->periodProblem($this->company))->toBeNull();

    Livewire::test(EuReports::class)
        ->set('period', '2026-K1')
        ->assertSee('Försäljning av varor redovisas per månad')
        ->set('contactName', 'Anna Andersson')
        ->set('phone', '08-123456')
        ->call('download')
        ->assertHasErrors('period')
        ->assertNoFileDownloaded();

    Livewire::test(EuReports::class)
        ->set('period', '2026-03')
        ->set('contactName', 'Anna Andersson')
        ->set('phone', '08-123456')
        ->call('download')
        ->assertHasNoErrors()
        ->assertFileDownloaded('periodisksammanstallning'.preg_replace('/[^0-9]/', '', (string) $this->company->org_number).'2603.txt');
});

it('treats triangulation as goods for the period rule', function () {
    psInvoice($this->danish, ForeignSale::EuTriangular, '13 600', '2026-03-10');

    expect(psList('2026-01-01', '2026-03-31')->periodProblem($this->company))->not->toBeNull();
});

it('allows a quarter for goods under a decision while the goods are at most 500 000 kr a quarter', function () {
    $this->company->forceFill(['eu_sales_quarterly_goods' => true, 'eu_sales_quarterly_goods_from' => '2026-01-01'])->save();

    // Exactly 500 000 kr in quarter 1: not more than the limit (35 kap. 4 § 2 "inte överstiger").
    psInvoice($this->danish, ForeignSale::EuGoods, '400 000', '2026-02-10');
    psInvoice($this->danish, ForeignSale::EuTriangular, '100 000', '2026-03-10');

    expect(EuSalesList::goodsValuePerQuarter(CarbonImmutable::parse('2026-02-15')))->toBe([
        'kvartal 1 2026' => 50_000_000,
        'kvartal 4 2025' => 0,
        'kvartal 3 2025' => 0,
        'kvartal 2 2025' => 0,
        'kvartal 1 2025' => 0,
    ])->and(psList('2026-01-01', '2026-03-31')->periodProblem($this->company))->toBeNull();

    // One more krona: 500 001 kr, the decision ends.
    psInvoice($this->danish, ForeignSale::EuGoods, '1', '2026-03-11');

    expect(psList('2026-01-01', '2026-03-31')->periodProblem($this->company))
        ->toContain("500\u{a0}001,00\u{a0}kr")
        ->toContain('kvartal 1 2026');
});

it('refuses a quarter when the goods exceeded 500 000 kr in one of the four quarters before', function () {
    $this->company->forceFill(['eu_sales_quarterly_goods' => true, 'eu_sales_quarterly_goods_from' => '2026-01-01'])->save();
    psInvoice($this->danish, ForeignSale::EuGoods, '600 000', '2026-01-15');
    $this->travelTo(CarbonImmutable::parse('2027-01-05 10:00:00'));
    psInvoice($this->danish, ForeignSale::EuGoods, '1 000', '2026-10-15');

    // Quarter 4 2026 looks back at quarters 3, 2 and 1 2026 and quarter 4 2025; quarter 1 had 600 000 kr.
    expect(psList('2026-10-01', '2026-12-31')->periodProblem($this->company))->toContain('kvartal 1 2026');

    // Quarter 1 2027 looks back to quarter 1 2026 too (the fourth quarter before it).
    expect(EuSalesList::goodsValuePerQuarter(CarbonImmutable::parse('2027-01-01')))->toHaveKey('kvartal 1 2026', 60_000_000);
});

it('refuses a quarter before the one the decision applies from', function () {
    $this->company->forceFill(['eu_sales_quarterly_goods' => true, 'eu_sales_quarterly_goods_from' => '2026-04-01'])->save();
    psInvoice($this->danish, ForeignSale::EuGoods, '1 000', '2026-03-10');

    expect(psList('2026-01-01', '2026-03-31')->periodProblem($this->company))->not->toBeNull();
});

it('notes that services alone are reported per quarter', function () {
    psInvoice($this->finnish, ForeignSale::EuServices, '5 000', '2026-03-10');

    expect(psList('2026-03-01', '2026-03-31')->periodNote())->toContain('Tjänster redovisas per kvartal')
        ->and(psList('2026-03-01', '2026-03-31')->periodProblem($this->company))->toBeNull()
        ->and(psList('2026-01-01', '2026-03-31')->periodNote())->toBeNull()
        ->and(psList('2026-01-01', '2026-03-31')->periodProblem($this->company))->toBeNull();
});

it('saves the decision on the company details page from the start of its quarter (SFL 35 kap. 6 §)', function () {
    Livewire::test(CompanyDetailsForm::class, ['company' => $this->company])
        ->set('state.eu_sales_quarterly_goods', true)
        ->set('state.eu_sales_quarterly_goods_from', '2026-05-15')
        ->call('save')
        ->assertHasNoErrors();

    $company = $this->company->fresh();

    expect($company->eu_sales_quarterly_goods)->toBeTrue()
        ->and($company->eu_sales_quarterly_goods_from?->toDateString())->toBe('2026-04-01');

    Livewire::test(CompanyDetailsForm::class, ['company' => $company])
        ->set('state.eu_sales_quarterly_goods', false)
        ->call('save');

    expect($company->fresh()->eu_sales_quarterly_goods_from)->toBeNull();
});

it('writes the reporter\'s VAT number as 12 digits and refuses a malformed one', function () {
    psInvoice($this->danish, ForeignSale::EuGoods, '21 700', '2026-03-10');

    // Skatteverket: 12 digits, "SE" optional (556000016701 and SE556000016701 both accepted).
    expect(EuSalesList::reporterVatNumber($this->company))->toBe('556334368901')
        ->and(psList('2026-03-01', '2026-03-31')->file($this->company, 'Anna Andersson', '08-123456', null))
        ->toBe("SKV574008;\r\n556334368901;2603;Anna Andersson;08-123456;\r\nDK31283522;21700;;;\r\n");

    $this->company->forceFill(['vat_number' => 'SE5563343689'])->save();

    expect(EuSalesList::reporterVatNumber($this->company))->toBeNull();

    Livewire::test(EuReports::class)
        ->set('period', '2026-03')
        ->set('contactName', 'Anna Andersson')
        ->set('phone', '08-123456')
        ->call('download')
        ->assertHasErrors('contactName')
        ->assertNoFileDownloaded();
});

it('counts only the company\'s own goods towards 500 000 kr', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::run($other, function () use ($otherOwner, $other) {
        $customer = app(SaveCustomer::class)($otherOwner, $other, ['name' => 'Eksempel ApS', 'vat_number' => 'DK31283522', 'country' => 'DK', 'address_line1' => 'Testgatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm', 'payment_terms_days' => 30]);
        app(IssueInvoice::class)($otherOwner, app(SaveDraftInvoice::class)($otherOwner, $other, [
            'customer_id' => $customer->id, 'invoice_date' => '2026-03-10', 'foreign_sale' => 'EU_GOODS',
            'rows' => [['description' => 'Leverans', 'quantity' => '1', 'price' => '900 000', 'vat_rate' => 2500]],
        ]));
    });
    $this->company->forceFill(['eu_sales_quarterly_goods' => true])->save();
    psInvoice($this->danish, ForeignSale::EuGoods, '1 000', '2026-03-10');

    expect(EuSalesList::goodsValuePerQuarter(CarbonImmutable::parse('2026-01-01'))['kvartal 1 2026'])->toBe(100_000)
        ->and(psList('2026-01-01', '2026-03-31')->periodProblem($this->company))->toBeNull();
});
