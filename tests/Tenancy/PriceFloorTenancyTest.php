<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Pricing\DecidePriceFloorApproval;
use App\Actions\Pricing\RequestPriceFloorApproval;
use App\Actions\Pricing\SetPriceFloor;
use App\Domain\Pricing\PriceFloorDocument;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Livewire\Pricing\DocumentMarginCard;
use App\Livewire\Pricing\FloorApprovalCard;
use App\Models\Article;
use App\Models\PriceFloorApproval;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\PricingScenario;

/*
 * AGENTS.md §13.2 for the price floor approvals (ADR-082 §9, P7): another
 * company's invoice, approval and floor answer 404 from every action and
 * screen, and its approvals never show in this company.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

    $this->scenario = PricingScenario::build(['articles' => ['A1' => ['price_ore' => 10000]], 'customer' => []]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    Article::query()->whereKey($this->scenario->articles['A1'])->update(['purchase_price_ore' => 8000]);
    app(SetPriceFloor::class)($this->owner, $this->company, '15', PriceFloorPolicy::RequireApproval);

    $this->invoice = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->scenario->customer->id,
        'invoice_date' => '2026-03-15',
        'rows' => [['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500, 'price' => '90,00', 'price_source' => 'MANUAL']],
    ]);
    $this->approval = app(RequestPriceFloorApproval::class)($this->owner, $this->company, PriceFloorDocument::Invoice, $this->invoice->id);

    [$this->otherOwner, $this->other] = Bookkeeping::company();
});

it('does not find another company\'s invoice when asking for an approval', function () {
    expect(fn () => app(RequestPriceFloorApproval::class)($this->otherOwner, $this->other, PriceFloorDocument::Invoice, $this->invoice->id))
        ->toThrow(ModelNotFoundException::class);
});

it('does not find another company\'s approval when deciding', function () {
    expect(fn () => app(DecidePriceFloorApproval::class)($this->otherOwner, $this->other, $this->approval->id, true))
        ->toThrow(ModelNotFoundException::class);

    expect($this->approval->fresh()->status->value)->toBe('PENDING');
});

it('shows another company none of the approvals and leaves its floor alone', function () {
    CurrentCompany::set($this->other);

    expect(PriceFloorApproval::query()->count())->toBe(0)
        ->and($this->other->fresh()->min_contribution_bp)->toBeNull();
});

it('answers 404 from the approval card and the contribution card for another company\'s invoice', function () {
    $this->actingAs($this->otherOwner);
    CurrentCompany::set($this->other);

    foreach ([FloorApprovalCard::class, DocumentMarginCard::class] as $card) {
        Livewire::test($card, ['type' => 'INVOICE', 'documentId' => $this->invoice->id])->assertNotFound();
    }
});
