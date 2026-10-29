<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Insights\BusinessKpis;
use App\Models\SalesDocument;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kvarterskrogen', 'payment_terms_days' => 10]);
    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'PIZ', 'name' => 'Pizza', 'unit' => 'st', 'price' => '100', 'vat_rate' => 1200]);

    // Cash sales of 5 000 kr, an invoice of 1 000 kr + 12 % VAT, part paid, and an open order.
    Bookkeeping::post($this->owner, $this->company, 500_000, 1930, 3001, '2026-03-01');

    $invoice = app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->customer->id,
        'invoice_date' => '2026-03-02',
        'rows' => [['article' => 'PIZ', 'description' => 'Pizza', 'quantity' => '10', 'price' => '100', 'vat_rate' => 1200]],
    ]));
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-05'), Money::parse('500'));

    app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::ORDER, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-02',
        'rows' => [['description' => 'Catering', 'quantity' => '1', 'price' => '2500', 'vat_rate' => 1200]],
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
});

it('sums sales, receivables, orders, result, bank and VAT in a few queries', function () {
    DB::enableQueryLog();
    $kpis = collect(app(BusinessKpis::class)->for($this->owner, $this->company))->mapWithKeys(fn (array $kpi) => [$kpi['label'] => $kpi['value']->ore]);
    $queries = count(DB::getQueryLog());

    expect($kpis->all())->toBe([
        'Fakturerat i mars' => 100_000,
        'Obetalt hos kunder' => 62_000,
        'Order att fakturera' => 250_000,
        'Resultat hittills 2026' => 600_000,
        'Pengar på bank och kassa' => 550_000,
        'Moms att betala' => 12_000,
    ])->and($queries)->toBeLessThanOrEqual(6);
});

it('warns about overdue invoices', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-25 10:00:00'));

    $receivables = collect(app(BusinessKpis::class)->for($this->owner, $this->company))->firstWhere('label', 'Obetalt hos kunder');

    expect($receivables['warn'])->toBeTrue()
        ->and($receivables['hint'])->toBe('1 faktura har förfallit, '.Money::ofOre(62_000)->format());
});

it('shows a role only the figures it may read', function () {
    $labels = collect(app(BusinessKpis::class)->for(Bookkeeping::member($this->company, 'approver'), $this->company))->pluck('label')->all();

    expect($labels)->toBe(['Fakturerat i mars', 'Obetalt hos kunder', 'Order att fakturera']);
});

it('shows the figures under every tab and per customer and article in the registers', function () {
    $this->get(route('invoicing.invoices.index'))->assertOk()->assertSee('Obetalt hos kunder')->assertSee('Pengar på bank och kassa');
    $this->get(route('accounting.vouchers.index'))->assertOk()->assertSee('Resultat hittills 2026');
    $this->get(route('dashboard'))->assertOk()->assertSee('Order att fakturera');

    $this->get(route('invoicing.customers'))->assertOk()
        ->assertSee('Register')
        ->assertSeeInOrder(['Kvarterskrogen', 'Fakturerat 2026', Money::ofOre(100_000)->format(), 'Obetalt', Money::ofOre(62_000)->format()]);

    $this->get(route('invoicing.articles'))->assertOk()->assertSeeInOrder(['Sålt 2026', 'Pizza', Money::ofOre(100_000)->format(), '10 st']);
});
