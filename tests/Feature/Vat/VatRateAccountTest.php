<?php

declare(strict_types=1);

/*
 * The VAT rate and the revenue account of a sale (#62, AUD-MOMS-4).
 * Skatteverket, "Fylla i momsdeklarationen" (read 2026-11-02), fält 05
 * (momspliktig försäljning) and fält 42 (försäljning som är undantagen från
 * moms och som inte ska redovisas i något annat fält):
 * https://skatteverket.se/foretag/moms/deklareramoms/fyllaimomsdeklarationen.4.3a2a542410ab40a421c80004214.html
 * A sale with no output VAT on its voucher is not taxable, so it is not in
 * box 05 whatever account carries it. Expected values are computed by hand.
 */

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Vat\SalesVatRules;
use App\Domain\Vat\VatReturn;
use App\Livewire\Accounting\VoucherEditor;
use App\Livewire\Invoicing\Articles;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kund i Sverige AB', 'payment_terms_days' => 30]);
});

/** @param  list<array<string, mixed>>  $rows */
function rateAccountInvoice(User $user, Company $company, int $customerId, array $rows): Invoice
{
    return app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $company, ['customer_id' => $customerId, 'invoice_date' => '2026-02-10', 'rows' => $rows]));
}

/** @param  list<array<string, mixed>>  $rows */
function rateAccountBook(User $user, Company $company, array $rows): void
{
    app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, ['voucher_date' => '2026-02-11', 'description' => 'Omföring', 'rows' => $rows]));
}

function rateAccountBoxes(FiscalYear $year): array
{
    return VatReturn::calculate($year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->boxes();
}

it('reverses a VAT-free sale on 3001 out of box 42 with its credit invoice', function () {
    $invoice = rateAccountInvoice($this->owner, $this->company, $this->customer->id, [['description' => 'Momsfri tjänst', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 0, 'account' => 3001]]);
    app(CreditInvoice::class)($this->owner, $invoice);
    $credit = Invoice::query()->where('credits_invoice_id', $invoice->id)->sole();
    app(IssueInvoice::class)($this->owner, $credit);

    // 1 000 kr into box 42 and 1 000 kr out again; nothing in box 05.
    $boxes = rateAccountBoxes($this->year);

    expect($boxes[42])->toBe(0)
        ->and($boxes[5])->toBe(0);
});

it('keeps a VAT-free sale in box 42 when the invoice rounds to whole kronor', function () {
    // 999,60 kr at 0 % rounds up 40 öre on 3740; the sale is still without VAT: box 42 = 999 kr (öre dropped).
    rateAccountInvoice($this->owner, $this->company, $this->customer->id, [['description' => 'Momsfri tjänst', 'quantity' => '1', 'price' => '999,60', 'vat_rate' => 0, 'account' => 3001]]);

    $boxes = rateAccountBoxes($this->year);

    expect($boxes[42])->toBe(999)
        ->and($boxes[5])->toBe(0);
});

it('reports a 25 % sale and a VAT-free sale on separate invoices in their own boxes', function () {
    rateAccountInvoice($this->owner, $this->company, $this->customer->id, [['description' => 'Konsult', 'quantity' => '1', 'price' => '2 000', 'vat_rate' => 2500, 'account' => 3001]]);
    rateAccountInvoice($this->owner, $this->company, $this->customer->id, [['description' => 'Momsfri tjänst', 'quantity' => '1', 'price' => '500', 'vat_rate' => 0, 'account' => 3001]]);

    // 2 000 kr in box 05 with 500 kr VAT; 500 kr in box 42.
    $boxes = rateAccountBoxes($this->year);

    expect($boxes[5])->toBe(2_000)
        ->and($boxes[10])->toBe(500)
        ->and($boxes[42])->toBe(500);
});

it('leaves a move between revenue accounts by account', function () {
    rateAccountInvoice($this->owner, $this->company, $this->customer->id, [['description' => 'Konsult', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500, 'account' => 3001]]);
    // 200 kr moved from 3001 to 3990 without VAT: a reclassification, not a sale without VAT.
    rateAccountBook($this->owner, $this->company, [
        ['account' => 3001, 'debit_ore' => 20_000],
        ['account' => 3990, 'credit_ore' => 20_000],
    ]);

    // 1 000 − 200 = 800 kr on box 05 accounts; nothing moves to box 42.
    $boxes = rateAccountBoxes($this->year);

    expect($boxes[5])->toBe(800)
        ->and($boxes[42])->toBe(0);
});

it('knows which rates the BAS sales accounts take', function () {
    expect(SalesVatRules::rates(3001))->toBe([2500])
        ->and(SalesVatRules::rates(3002))->toBe([1200])
        ->and(SalesVatRules::rates(3003))->toBe([600])
        ->and(SalesVatRules::rates(3004))->toBe([0])
        ->and(SalesVatRules::rates(3010))->toBe([2500, 1200, 600])
        ->and(SalesVatRules::rates(1930))->toBeNull()
        ->and(SalesVatRules::outputVat(3001))->toBe([2610, 2611])
        ->and(SalesVatRules::outputVat(3004))->toBeNull()
        ->and(SalesVatRules::mismatch(3001, 2500))->toBeNull()
        ->and(SalesVatRules::mismatch(3001, 0))->toBe('Konto 3001 är för försäljning med 25 % moms, men momssatsen är 0 %. Momsdeklarationen följer momsen på verifikationen, så kontrollera att kontot är rätt.');
});

it('warns when an article\'s rate does not fit its account', function () {
    Livewire::test(Articles::class)
        ->call('create')
        ->set('form.number', 'FRI')
        ->set('form.name', 'Momsfri kurs')
        ->set('form.price', '1000')
        ->set('form.vat_rate', '0')
        ->set('form.account', '3001')
        ->call('save')
        ->assertDispatched('toast', message: 'Artikel FRI Momsfri kurs är tillagd. Konto 3001 är för försäljning med 25 % moms, men momssatsen är 0 %. Momsdeklarationen följer momsen på verifikationen, så kontrollera att kontot är rätt.');
});

it('gives the editors the VAT rules of the sales accounts', function () {
    $voucherRules = Livewire::test(VoucherEditor::class)->viewData('vatRules');
    $invoiceRules = Livewire::test(InvoiceEditor::class)->viewData('vatRules');

    expect($voucherRules[3001])->toBe(['rates' => [2500], 'vat' => [2610, 2611]])
        ->and($voucherRules[3004])->toBe(['rates' => [0], 'vat' => null])
        ->and($voucherRules)->not->toHaveKey(1930)
        ->and($invoiceRules[3002])->toBe(['rates' => [1200], 'vat' => [2620, 2621]]);
});
