<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Pricing\DecidePriceFloorApproval;
use App\Actions\Pricing\RequestPriceFloorApproval;
use App\Actions\Pricing\SetPriceFloor;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignInSession;
use App\Domain\Pricing\DocumentMargin;
use App\Domain\Pricing\PriceFloorDocument;
use App\Domain\Pricing\PriceFloorGuard;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Models\Article;
use App\Models\Invoice;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\PricingScenario;

/*
 * ADR-082 §9 (P7), the cold review's findings: a typed discount on a list
 * price, a recurring draft issued by a person, a quote sent or accepted with
 * rows that were not approved, negative rows, who edited a quote, another
 * version (customer, date, currency) and another currency, a floor of 0 %.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();

    $this->scenario = PricingScenario::build(['articles' => ['A1' => ['price_ore' => 10000], 'A2' => ['price_ore' => 10000]], 'rate' => ['currency' => 'EUR', 'date' => '2026-03-13', 'micros' => 11_000_000], 'customer' => []]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    $this->customer = $this->scenario->customer;
    $this->writer = Bookkeeping::member($this->company, 'bookkeeper');
    $this->approver = Bookkeeping::member($this->company, 'accountant');
    $this->accountant = Bookkeeping::member($this->company, 'accountant');
    $this->actingAs($this->owner);

    Article::query()->whereKey($this->scenario->articles['A1'])->update(['purchase_price_ore' => 8000]);
    app(SetPriceFloor::class)($this->owner, $this->company, '15', PriceFloorPolicy::RequireApproval);
    $this->company = $this->company->fresh();
});

function r7Row(array $row = []): array
{
    return ['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '1', 'vat_rate' => 2500, ...$row];
}

function r7Invoice(array $rows, ?Invoice $draft = null, array $extra = []): Invoice
{
    $s = test();

    return app(SaveDraftInvoice::class)($s->writer, $s->company, ['customer_id' => $s->customer->id, 'invoice_date' => '2026-03-15', 'rows' => $rows, ...$extra], $draft);
}

function r7Quote(array $rows, ?SalesDocument $document = null, string $currency = 'SEK', $by = null): SalesDocument
{
    $s = test();

    return app(SaveSalesDocument::class)($by ?? $s->writer, $s->company, SalesDocument::QUOTE, [
        'customer_id' => $s->customer->id,
        'document_date' => '2026-03-15',
        'until_date' => '2026-04-15',
        'currency' => $currency,
        'rows' => $rows,
    ], $document);
}

function r7Approve(PriceFloorDocument $type, string $id, $asker = null): void
{
    $s = test();
    $approval = app(RequestPriceFloorApproval::class)($asker ?? $s->writer, $s->company, $type, $id);
    app(DecidePriceFloorApproval::class)($s->approver, $s->company, $approval->id, true);
}

it('holds a typed discount on a list price: the row is manual', function () {
    // The base price 100,00 is 25 % over the cost of 80,00; half of it typed off is under cost.
    $invoice = r7Invoice([r7Row(['discount' => '50'])]);

    expect($invoice->rows->first()->discount_bp)->toBe(5000)
        ->and(fn () => app(IssueInvoice::class)($this->owner, $invoice))->toThrow(ValidationException::class, 'lägsta täckningsgrad');

    r7Approve(PriceFloorDocument::Invoice, $invoice->id);

    expect(app(IssueInvoice::class)($this->owner, $invoice->fresh())->number)->not->toBeNull();
});

it('lets a list price without a typed discount through', function () {
    expect(app(IssueInvoice::class)($this->owner, r7Invoice([r7Row()]))->number)->not->toBeNull();
});

it('holds a recurring draft issued by a person, and lets the agreement\'s own automatic issue through', function () {
    $draft = r7Invoice([r7Row(['price' => '10,00', 'price_source' => 'MANUAL'])]);
    // Whoever made the draft, a person issuing it is held; only the agreement's own automatic issue is not.
    expect(fn () => app(IssueInvoice::class)($this->owner, $draft))->toThrow(ValidationException::class);

    expect(app(IssueInvoice::class)($this->owner, $draft->fresh(), automatic: true)->number)->not->toBeNull();
});

it('refuses to send a quote for signing until its prices are approved', function () {
    $quote = r7Quote([r7Row(['price' => '90,00', 'price_source' => 'MANUAL'])]);

    expect(fn () => app(SendForSigning::class)($this->owner, $this->company, $quote, ['email' => 'kund@example.test']))->toThrow(ValidationException::class, 'lägsta täckningsgrad');

    r7Approve(PriceFloorDocument::Quote, $quote->id);

    $sent = app(SendForSigning::class)($this->owner, $this->company, $quote->fresh(), ['email' => 'kund@example.test']);

    expect($sent['url'])->not->toBe('');
});

it('does not let the customer accept, or the quote become an order, after the rows changed since the approval', function () {
    $quote = r7Quote([r7Row(['price' => '90,00', 'price_source' => 'MANUAL'])]);
    r7Approve(PriceFloorDocument::Quote, $quote->id);
    app(SendForSigning::class)($this->owner, $this->company, $quote->fresh(), ['email' => 'kund@example.test']);

    // The sent and approved quote is changed: the portal would show these rows.
    $changed = r7Quote([r7Row(['price' => '81,00', 'price_source' => 'MANUAL'])], $quote->fresh());
    $signer = ['email' => 'kund@example.test', 'name' => 'Kund Kundsson', 'contact_id' => null, 'ip' => null, 'user_agent' => null];

    try {
        app(SignInSession::class)->accept($changed->fresh(), $signer, 'Jag godkänner.', $changed->fresh()->signingFingerprint());
        $this->fail('Expected a refusal.');
    } catch (ValidationException $exception) {
        // The customer is told nothing of the floor.
        expect($exception->errors()['document'][0])->toContain('Kontakta avsändaren')->not->toContain('täckningsgrad');
    }

    expect(fn () => app(ProgressSalesDocument::class)->toOrder($this->owner, $changed->fresh()))->toThrow(ValidationException::class, 'lägsta täckningsgrad');
});

it('counts a discount row as less sold: a manual price with a free-text discount under the floor is held', function () {
    $invoice = r7Invoice([
        r7Row(['price' => '100,00', 'price_source' => 'MANUAL']),
        ['description' => 'Rabatt', 'quantity' => '1', 'price' => '-50,00', 'vat_rate' => 2500],
    ]);

    $state = PriceFloorGuard::state($this->company, $invoice->fresh());

    expect($state->needed)->toBeTrue()
        ->and(fn () => app(IssueInvoice::class)($this->owner, $invoice))->toThrow(ValidationException::class);
});

it('lets a small discount row through when the document as a whole stays over the floor', function () {
    $invoice = r7Invoice([
        r7Row(['price' => '100,00', 'price_source' => 'MANUAL']),
        ['description' => 'Rabatt', 'quantity' => '1', 'price' => '-5,00', 'vat_rate' => 2500],
    ]);

    expect(PriceFloorGuard::state($this->company, $invoice->fresh())->needed)->toBeFalse();
});

it('does not let the person who changed a quote approve it', function () {
    $quote = r7Quote([r7Row(['price' => '90,00', 'price_source' => 'MANUAL'])]);
    r7Quote([r7Row(['price' => '90,00', 'price_source' => 'MANUAL'])], $quote->fresh(), by: $this->accountant);
    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Quote, $quote->id);

    expect(fn () => app(DecidePriceFloorApproval::class)($this->accountant, $this->company, $approval->id, true))->toThrow(ValidationException::class, 'skrivit');

    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, true);

    expect($approval->fresh()->status->value)->toBe('APPROVED');
});

it('makes another date, customer or currency another version', function () {
    $invoice = r7Invoice([r7Row(['price' => '90,00', 'price_source' => 'MANUAL'])]);
    r7Approve(PriceFloorDocument::Invoice, $invoice->id);

    expect(PriceFloorGuard::state($this->company, $invoice->fresh())->cleared())->toBeTrue();

    $moved = r7Invoice([r7Row(['price' => '90,00', 'price_source' => 'MANUAL'])], $invoice->fresh(), ['invoice_date' => '2026-03-16']);

    expect(PriceFloorGuard::state($this->company, $moved->fresh())->cleared())->toBeFalse();
});

it('holds a typed price in another currency, since its margin cannot be checked', function () {
    $quote = r7Quote([r7Row(['price' => '10,00', 'price_source' => 'MANUAL'])], currency: 'EUR');
    $state = PriceFloorGuard::state($this->company, $quote->fresh());

    expect($state->needed)->toBeTrue()->and($state->lines)->toBe([1]);

    r7Approve(PriceFloorDocument::Quote, $quote->id);

    expect(PriceFloorGuard::state($this->company, $quote->fresh())->cleared())->toBeTrue();
});

it('refuses a floor of 0 percent', function () {
    foreach (['0', '0,00', '0.004'] as $value) {
        expect(fn () => app(SetPriceFloor::class)($this->owner, $this->company, $value, PriceFloorPolicy::Warn))->toThrow(ValidationException::class);
    }

    expect(app(SetPriceFloor::class)($this->owner, $this->company, '0,01', PriceFloorPolicy::Warn)->min_contribution_bp)->toBe(1);
});

it('holds a free-text discount row in another currency, as in kronor, but not freight', function () {
    $discounted = r7Quote([r7Row(), ['description' => 'Rabatt', 'quantity' => '1', 'price' => '-8,00', 'vat_rate' => 2500]], currency: 'EUR');
    $freight = r7Quote([r7Row(), ['description' => 'Frakt', 'quantity' => '1', 'price' => '1,00', 'vat_rate' => 2500]], currency: 'EUR');

    expect($discounted->rows->first()->price_source->value)->toBe('CONVERTED')
        ->and(PriceFloorGuard::state($this->company, $discounted->fresh())->lines)->toBe([2])
        ->and(PriceFloorGuard::state($this->company, $freight->fresh())->needed)->toBeFalse();
});

it('does not let someone who edited a quote while the floor was off approve it once it is on', function () {
    app(SetPriceFloor::class)($this->owner, $this->company, null, PriceFloorPolicy::Warn);
    $this->company = $this->company->fresh();

    $quote = r7Quote([r7Row(['price' => '95,00', 'price_source' => 'MANUAL'])]);
    r7Quote([r7Row(['price' => '90,00', 'price_source' => 'MANUAL'])], $quote->fresh(), by: $this->accountant);
    $invoice = r7Invoice([r7Row(['price' => '95,00', 'price_source' => 'MANUAL'])]);
    app(SaveDraftInvoice::class)($this->accountant, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [r7Row(['price' => '90,00', 'price_source' => 'MANUAL'])]], $invoice);

    app(SetPriceFloor::class)($this->owner, $this->company, '15', PriceFloorPolicy::RequireApproval);
    $this->company = $this->company->fresh();

    foreach ([[PriceFloorDocument::Quote, $quote], [PriceFloorDocument::Invoice, $invoice]] as [$type, $document]) {
        $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, $type, $document->id);

        expect(fn () => app(DecidePriceFloorApproval::class)($this->accountant, $this->company, $approval->id, true))->toThrow(ValidationException::class, 'skrivit');
    }
});

it('lets freight and a comment row through when only a list price sits under the floor', function () {
    // The list price 100,00 is 5 % over a cost of 95,00, under the floor of 15 %: a list price only warns.
    Article::query()->whereKey($this->scenario->articles['A1'])->update(['purchase_price_ore' => 9500]);

    $freight = r7Invoice([r7Row(), ['description' => 'Frakt', 'quantity' => '1', 'price' => '10,00', 'vat_rate' => 2500]]);
    $comment = r7Invoice([r7Row(), ['description' => 'Se bifogad specifikation', 'quantity' => '1', 'price' => '0,00', 'vat_rate' => 2500]]);
    $discount = r7Invoice([r7Row(), ['description' => 'Rabatt', 'quantity' => '1', 'price' => '-5,00', 'vat_rate' => 2500]]);

    expect(PriceFloorGuard::state($this->company, $freight->fresh())->needed)->toBeFalse()
        ->and(PriceFloorGuard::state($this->company, $comment->fresh())->needed)->toBeFalse()
        ->and(PriceFloorGuard::state($this->company, $discount->fresh())->needed)->toBeTrue()
        ->and(PriceFloorGuard::state($this->company, $discount->fresh())->lines)->toBe([2]);
});

it('holds a return of another article at its list price, in kronor and in euro', function () {
    // A2 has no known cost: -1 of it at the list price lowers 200,00 off the sales of 200,00 of A1 (cost 160,00).
    foreach (['SEK', 'EUR'] as $currency) {
        $quote = r7Quote([
            r7Row(['quantity' => '2', 'price' => $currency === 'SEK' ? '100,00' : '9,09', 'price_source' => 'MANUAL']),
            r7Row(['article' => 'A2', 'description' => 'Retur A2', 'quantity' => '-1']),
        ], currency: $currency);
        $state = PriceFloorGuard::state($this->company, $quote->fresh());

        expect($state->needed)->toBeTrue($currency)->and($state->lines)->toContain(2);
    }
});

it('lets a real partial return of the same article keep its margin', function () {
    $invoice = r7Invoice([
        r7Row(['quantity' => '2', 'price' => '100,00', 'price_source' => 'MANUAL']),
        r7Row(['quantity' => '-1', 'price' => '100,00', 'price_source' => 'MANUAL']),
    ]);
    $margin = DocumentMargin::ofDocument($this->company, $invoice->fresh());

    expect($margin->netOre)->toBe(10000)
        ->and($margin->costOre)->toBe(8000)
        ->and($margin->marginBp())->toBe(2000)
        ->and($margin->needsApproval())->toBeFalse();
});
