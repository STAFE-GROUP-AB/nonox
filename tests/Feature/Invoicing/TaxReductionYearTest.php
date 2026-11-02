<?php

declare(strict_types=1);

/*
 * ROT and RUT follow the year the buyer pays (AUD-FAKT-10, #56): the
 * reduction is claimed for the tax year in which the costs were paid
 * (Inkomstskattelag (1999:1229) 67 kap. 12 § p. 1, read 2026-11-02) and the
 * ceilings apply per tax year (67 kap. 19 §; lag (2009:194) 7-8 §§). The
 * invoice uses its due date as the expected payment day; the payment
 * re-checks against the actual day.
 */

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Livewire\Invoicing\InvoiceActions;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->household = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Anna Andersson', 'personal_number' => '19121212-1212', 'payment_terms_days' => 30,
        'address_line1' => 'Testgatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm',
    ]);

    // Labour 133 334 kr + 25 % = 166 667,50 kr; 30 % = 50 000,25 → 50 000 kr: the 2026 ROT ceiling used up (due 2026-04-01).
    $this->first = app(IssueInvoice::class)($this->owner, rotYearDraft('2026-03-02', '133 334'));
});

function rotYearDraft(string $date, string $price): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => test()->household->id, 'invoice_date' => $date, 'tax_reduction' => 'ROT', 'property_designation' => 'Uppsala Kåbo 1:23',
        'rows' => [['description' => 'Renovering, arbete', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500, 'labour' => true]],
    ]);
}

it('uses the ceiling of the year the invoice is expected to be paid, not the invoice date', function () {
    // Invoiced 2026-12-10, due 2027-01-09: 2027 has the whole 50 000 kr left.
    // Labour 5 000 + 25 % = 6 250 kr; 30 % = 1 875 kr.
    $december = rotYearDraft('2026-12-10', '5 000');

    expect($this->first->tax_reduction_ore->ore)->toBe(5_000_000)
        ->and($december->due_date->toDateString())->toBe('2027-01-09')
        ->and($december->tax_reduction_ore->ore)->toBe(187_500)
        ->and($december->total_ore->ore)->toBe(625_000 - 187_500);
});

it('gives nothing more in a year whose ceiling is used up', function () {
    // Due 2026-12-01 in 2026, where 50 000 kr is already taken.
    expect(rotYearDraft('2026-11-01', '5 000')->tax_reduction_ore->ore)->toBe(0);
});

it('re-checks the deduction when the buyer pays in another year than expected', function () {
    $december = app(IssueInvoice::class)($this->owner, rotYearDraft('2026-12-10', '5 000'));

    // Paid 2026-12-20, in 2026, whose ceiling the first invoice used up: 0 kr would be allowed, not 1 875.
    app(RegisterPayment::class)($this->owner, $december, CarbonImmutable::parse('2026-12-20'), $december->total_ore);

    $log = AuditLog::query()->where('action', 'invoice.payment_registered')->where('subject_id', $december->id)->latest('id')->firstOrFail();

    expect($log->payload['tax_reduction_recheck'])->toBe(['year' => 2026, 'allowed' => 0, 'invoiced' => 187_500]);

    Livewire::test(InvoiceActions::class, ['invoice' => $december->fresh()])
        ->assertSee('Kunden betalade 2026')
        ->assertSee(Money::ofOre(187_500)->format());
});

it('re-checks only the payment that settles the invoice', function () {
    $december = app(IssueInvoice::class)($this->owner, rotYearDraft('2026-12-10', '5 000'));
    app(RegisterPayment::class)($this->owner, $december, CarbonImmutable::parse('2026-12-30'), Money::ofOre(100));

    expect(AuditLog::query()->where('subject_id', $december->id)->where('action', 'invoice.payment_registered')->firstOrFail()->payload)->not->toHaveKey('tax_reduction_recheck');
});
