<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Modules\Crm\Domain\AccountFigures;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Key figures per account from the ledger (ADR-043), against values worked
 * out by hand. All invoices without VAT on the rows' net amounts; VAT 25 %.
 *
 *   Inv1 2026-01-10  A1 2 × 1 000 + free text 500 = 2 500 net, 3 125 total,
 *                    paid in full 2026-02-19 (40 days after the invoice date)
 *   Inv2 2026-05-01  A1 1 × 1 000 + free text 1 000 = 2 000 net, 2 500 total,
 *                    due 2026-05-31, 1 250 paid 2026-05-21 (20 days)
 *   Inv3 2026-06-10  free text 400 net, 500 total, credited in full
 *   A1 has the purchase price 600; the free-text rows have none.
 *
 * Seen on 2027-02-15, the last 12 months start 2026-02-16:
 *   invoiced 12 months  2 000 + 400 − 400            =   2 000 kr
 *   invoiced in total   2 500 + 2 000 + 400 − 400    =   4 500 kr
 *   open and overdue    2 500 − 1 250                =   1 250 kr
 *   payment days        (3 125 × 40 + 1 250 × 20) / 4 375 = 34.29 → 34.3
 *   margin base         the A1 row of Inv2           =   1 000 kr
 *   margin              1 000 − 1 × 600              =     400 kr (40 %)
 *   coverage            1 000 / (2 000 + 400 − 400)  =     50 %
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);

    $article = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A1', 'name' => 'Konsulttimme', 'unit' => 'st', 'price' => '1000,00', 'vat_rate' => 2500]);
    $article->forceFill(['purchase_price_ore' => 60000])->save();

    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['payment_terms_days' => 30]);
    $other = Crm::customer($this->company, $this->owner, 'Någon annan AB');
    $pay = app(RegisterPayment::class);

    $inv1 = Crm::invoice($this->owner, $this->company, $this->customer, '2026-01-10', [
        ['article' => 'A1', 'description' => 'Konsulttimme', 'quantity' => '2', 'price' => '1000', 'vat_rate' => 2500],
        ['description' => 'Resa', 'quantity' => '1', 'price' => '500', 'vat_rate' => 2500],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-02-19 10:00:00'));
    $pay($this->owner, $inv1, CarbonImmutable::parse('2026-02-19'), Money::ofOre(312500));

    $this->travelTo(CarbonImmutable::parse('2026-05-01 10:00:00'));
    $inv2 = Crm::invoice($this->owner, $this->company, $this->customer, '2026-05-01', [
        ['article' => 'A1', 'description' => 'Konsulttimme', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500],
        ['description' => 'Material', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-05-21 10:00:00'));
    $pay($this->owner, $inv2, CarbonImmutable::parse('2026-05-21'), Money::ofOre(125000));

    $this->travelTo(CarbonImmutable::parse('2026-06-10 10:00:00'));
    $inv3 = Crm::invoice($this->owner, $this->company, $this->customer, '2026-06-10', [
        ['description' => 'Tillägg', 'quantity' => '1', 'price' => '400', 'vat_rate' => 2500],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-06-12 10:00:00'));
    app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $inv3));

    // Neither a draft nor another customer's invoice counts.
    app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-12', 'rows' => [['description' => 'Utkast', 'quantity' => '1', 'price' => '9999', 'vat_rate' => 2500]]]);
    Crm::invoice($this->owner, $this->company, $other, '2026-06-12', [['article' => 'A1', 'description' => 'Annan', 'quantity' => '5', 'price' => '1000', 'vat_rate' => 2500]]);
});

it('computes the key figures from the ledger as worked out by hand', function () {
    $figures = app(AccountFigures::class)->forCustomer($this->customer->id, CarbonImmutable::parse('2027-02-15'));

    expect($figures->invoiced12Months)->toBe(200000)
        ->and($figures->invoicedTotal)->toBe(450000)
        ->and($figures->open)->toBe(125000)
        ->and($figures->overdue)->toBe(125000)
        ->and($figures->paymentDays)->toBe(34.3)
        ->and($figures->marginBase)->toBe(100000)
        ->and($figures->margin)->toBe(40000)
        ->and($figures->marginRate())->toBe(0.4)
        ->and($figures->marginCoverage)->toBe(0.5);
});

it('counts a receivable as overdue only after its due date', function () {
    $figures = app(AccountFigures::class)->forCustomer($this->customer->id, CarbonImmutable::parse('2026-05-30'));

    // Inv2 is due 2026-05-31; Inv3 is credited and settled.
    expect($figures->open)->toBe(125000)->and($figures->overdue)->toBe(0);
});

it('answers for any number of customers in three queries, and with nothing for a customer without invoices', function () {
    $empty = Crm::customer($this->company, $this->owner, 'Ny kund AB');

    DB::enableQueryLog();
    $all = app(AccountFigures::class)->forCustomers([$this->customer->id, $empty->id], CarbonImmutable::parse('2027-02-15'));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(3)
        ->and($all)->toHaveKey($this->customer->id)
        ->and($all)->not->toHaveKey($empty->id)
        ->and(app(AccountFigures::class)->forCustomer($empty->id)->invoicedTotal)->toBe(0)
        ->and(app(AccountFigures::class)->forCustomer(null)->paymentDays)->toBeNull();
});
