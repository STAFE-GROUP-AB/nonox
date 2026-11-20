<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Pricing\DecidePriceFloorApproval;
use App\Actions\Pricing\RequestPriceFloorApproval;
use App\Actions\Pricing\SetPriceFloor;
use App\Domain\Pricing\MarginReport;
use App\Domain\Pricing\PriceFloorDocument;
use App\Domain\Pricing\PriceFloorGuard;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Livewire\Invoicing\SalesDocumentEditor;
use App\Livewire\Pricing\DocumentMarginCard;
use App\Livewire\Pricing\FloorApprovalCard;
use App\Livewire\Pricing\FloorSettings;
use App\Models\Article;
use App\Models\Invoice;
use App\Models\PriceFloorApproval;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\PricingScenario;

/*
 * ADR-082 §9 (P7), the screens: the editors' margin check (cost and
 * contribution only with prices:view-cost, a fixed number of queries), the
 * approval card on a document, the floor setting, and the contribution card
 * on an issued invoice.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

    $this->scenario = PricingScenario::build(['articles' => ['A1' => ['price_ore' => 10000]], 'customer' => []]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    $this->customer = $this->scenario->customer;
    $this->writer = Bookkeeping::member($this->company, 'bookkeeper');
    $this->approver = Bookkeeping::member($this->company, 'accountant');
    $this->viewer = Bookkeeping::member($this->company, 'approver');
    $this->actingAs($this->owner);

    Article::query()->whereKey($this->scenario->articles['A1'])->update(['purchase_price_ore' => 8000]);
    $this->unitId = (int) Unit::query()->where('code', 'st')->value('id');
});

function p7SetFloor(string $percent = '15'): void
{
    app(SetPriceFloor::class)(test()->owner, test()->company, $percent, PriceFloorPolicy::RequireApproval);
    // The acting user keeps its company from before in this process; a real request loads it again.
    test()->owner = test()->owner->fresh();
    test()->actingAs(test()->owner);
}

function p7Rows(string $price, string $source = 'MANUAL', string $quantity = '1'): array
{
    return [['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => $quantity, 'unit_id' => (string) test()->unitId, 'price' => $price, 'discount' => '', 'vat_rate' => '2500', 'price_source' => $source]];
}

function p7Check(array $rows): array
{
    $answer = [];

    Livewire::test(InvoiceEditor::class)->call('marginCheck', $rows)->assertReturned(function (mixed $data) use (&$answer): bool {
        $answer = $data;

        return true;
    });

    return $answer;
}

it('tells a person with prices:view-cost when a row is under cost, with the contribution of the document', function () {
    $answer = p7Check(p7Rows('70,00'));

    expect($answer['rows'][0]['below_cost'])->toBeTrue()
        ->and($answer['rows'][0]['text'])->toContain('Under självkostnad')->toContain('70,00')->toContain('80,00')
        ->and($answer['totals']['margin'])->toBe('-14,29 %')
        ->and($answer['banner'])->toBe('');
});

it('shows the contribution of a row that is over cost without a warning', function () {
    $answer = p7Check(p7Rows('100,00'));

    expect($answer['rows'])->toBe([])
        ->and($answer['totals']['contribution'])->toContain('20,00')
        ->and($answer['totals']['margin'])->toBe('20 %');
});

it('says a typed price under the floor needs approval, and a list price only warns', function () {
    p7SetFloor();

    $typed = p7Check(p7Rows('90,00'));
    $listed = p7Check(p7Rows('90,00', 'BASE'));

    expect($typed['rows'][0]['needs_approval'])->toBeTrue()
        ->and($typed['rows'][0]['below_cost'])->toBeFalse()
        ->and($typed['rows'][0]['text'])->toContain('15 %')
        ->and($typed['banner'])->toContain('rad 1')
        ->and($listed['rows'][0]['below_floor'])->toBeTrue()
        ->and($listed['rows'][0]['needs_approval'])->toBeFalse()
        ->and($listed['banner'])->toBe('');
});

it('never tells a person without prices:view-cost what anything costs', function () {
    p7SetFloor();
    $lines = [['article_id' => $this->scenario->articles['A1'], 'quantity' => '1', 'unit' => 'st', 'net_ore' => 7000, 'manual' => true]];

    $answer = MarginReport::for($this->company->fresh(), $lines, [0], false);

    expect($answer['totals'])->toBeNull()
        ->and($answer['rows'][0]['below_cost'])->toBeFalse()
        ->and($answer['rows'][0]['below_floor'])->toBeTrue()
        ->and(json_encode($answer))->not->toContain('80,00')->not->toContain('självkostnad');
});

it('says nothing about margin for a quote in another currency but a note', function () {
    $answer = [];

    Livewire::test(SalesDocumentEditor::class, ['kind' => 'QUOTE'])
        ->set('currency', 'EUR')
        ->call('marginCheck', p7Rows('70,00'))
        ->assertReturned(function (mixed $data) use (&$answer): bool {
            $answer = $data;

            return true;
        });

    expect($answer['rows'])->toBe([])->and($answer['totals'])->toBeNull()->and($answer['note'])->toContain('kronor');
});

it('answers in the same number of queries for 1 and for 300 rows with costs', function () {
    $template = Article::query()->whereKey($this->scenario->articles['A1'])->firstOrFail();
    $rows = [];

    foreach (range(1, 300) as $i) {
        $copy = $template->replicate();
        $copy->number = "B{$i}";
        $copy->purchase_price_ore = 5000 + $i;
        $copy->save();
        $rows[] = ['article' => "B{$i}", 'description' => "B{$i}", 'quantity' => '2', 'unit_id' => (string) $this->unitId, 'price' => '60,00', 'discount' => '5', 'vat_rate' => '2500', 'price_source' => 'MANUAL'];
    }

    p7SetFloor();

    $count = function (array $given): int {
        $component = Livewire::test(InvoiceEditor::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('marginCheck', $given);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count(array_slice($rows, 0, 1));
    $long = $count($rows);

    expect($long)->toBe($short);
});

it('prices the same through the editor and the saved document', function () {
    p7SetFloor();

    $draft = app(SaveDraftInvoice::class)($this->writer, $this->company, [
        'customer_id' => $this->customer->id,
        'invoice_date' => '2026-03-15',
        'rows' => [['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500, 'price' => '90,00', 'price_source' => 'MANUAL']],
    ]);
    $this->actingAs($this->writer);

    $answer = p7Check(p7Rows('90,00'));

    expect($answer['banner'])->toContain('rad 1')
        ->and(PriceFloorGuard::state($this->company->fresh(), $draft)->lines)->toBe([1]);
});

it('shows the approval card only while a price needs approval, and walks it through', function () {
    p7SetFloor();
    $draft = app(SaveDraftInvoice::class)($this->writer, $this->company, [
        'customer_id' => $this->customer->id,
        'invoice_date' => '2026-03-15',
        'rows' => [['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500, 'price' => '90,00', 'price_source' => 'MANUAL']],
    ]);

    $this->actingAs($this->writer);
    Livewire::test(FloorApprovalCard::class, ['type' => 'INVOICE', 'documentId' => $draft->id])
        ->assertSee('rad 1')->assertSee('15 %')->assertSee('Be om godkännande')->assertDontSee('80,00')
        ->call('askForApproval')
        ->assertSee('Väntar på godkännande')
        ->assertDontSee('Godkänn priserna')
        ->assertSee('Det kräver behörigheten');

    $this->actingAs($this->approver);
    Livewire::test(FloorApprovalCard::class, ['type' => 'INVOICE', 'documentId' => $draft->id])
        ->assertSee('Godkänn priserna')
        ->call('reject', PriceFloorApproval::query()->firstOrFail()->id)
        ->assertHasErrors('reason')
        ->set('reason', 'För lågt pris.')
        ->call('reject', PriceFloorApproval::query()->firstOrFail()->id)
        ->assertSee('Avslagen av')->assertSee('För lågt pris.');

    $this->actingAs($this->writer);
    Livewire::test(FloorApprovalCard::class, ['type' => 'INVOICE', 'documentId' => $draft->id])->call('askForApproval');

    $this->actingAs($this->approver);
    $pending = PriceFloorApproval::query()->where('status', 'PENDING')->firstOrFail();
    Livewire::test(FloorApprovalCard::class, ['type' => 'INVOICE', 'documentId' => $draft->id])
        ->call('approve', $pending->id)
        ->assertSee('Godkänt av');

    expect($pending->fresh()->status->value)->toBe('APPROVED');
});

it('renders nothing when the company has no floor or no price is under it', function () {
    $draft = app(SaveDraftInvoice::class)($this->writer, $this->company, [
        'customer_id' => $this->customer->id,
        'invoice_date' => '2026-03-15',
        'rows' => [['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500, 'price' => '90,00', 'price_source' => 'MANUAL']],
    ]);

    Livewire::test(FloorApprovalCard::class, ['type' => 'INVOICE', 'documentId' => $draft->id])->assertDontSee('lägsta täckningsgrad');

    p7SetFloor('5');

    Livewire::test(FloorApprovalCard::class, ['type' => 'INVOICE', 'documentId' => $draft->id])->assertDontSee('lägsta täckningsgrad');
});

it('saves the floor from the price list page for someone who may manage prices, and shows it read-only to others', function () {
    Livewire::test(FloorSettings::class)
        ->set('percent', '15')->set('policy', 'REQUIRE_APPROVAL')->call('save')
        ->assertHasNoErrors();

    expect($this->company->fresh()->min_contribution_bp)->toBe(1500)
        ->and($this->company->fresh()->price_floor_policy)->toBe(PriceFloorPolicy::RequireApproval);

    Livewire::test(FloorSettings::class)->set('percent', '150')->call('save')->assertHasErrors('percent');

    $this->actingAs($this->writer);
    Livewire::test(FloorSettings::class)->assertSet('percent', '15')->assertSee('kan se inställningen');
    Livewire::test(FloorSettings::class)->call('save')->assertForbidden();

    expect($this->company->fresh()->min_contribution_bp)->toBe(1500);
});

it('shows the contribution of an issued invoice only to someone with prices:view-cost', function () {
    $draft = app(SaveDraftInvoice::class)($this->writer, $this->company, [
        'customer_id' => $this->customer->id,
        'invoice_date' => '2026-03-15',
        'rows' => [['article' => 'A1', 'description' => 'A1', 'quantity' => '1', 'vat_rate' => 2500]],
    ]);
    $invoice = app(IssueInvoice::class)($this->owner, $draft);

    $this->actingAs($this->writer);
    Livewire::test(DocumentMarginCard::class, ['type' => 'INVOICE', 'documentId' => $invoice->id])
        ->assertSee('Täckningsbidrag')->assertSee('20 %');

    $this->actingAs($this->viewer);
    Livewire::test(DocumentMarginCard::class, ['type' => 'INVOICE', 'documentId' => $invoice->id])
        ->assertDontSee('Täckningsbidrag');

});

it('keeps the editor\'s save and issue as they were: an under-floor typed price blocks only the send', function () {
    p7SetFloor();

    Livewire::test(InvoiceEditor::class)
        ->set('customerId', (string) $this->customer->id)
        ->set('rows', p7Rows('90,00'))
        ->call('save');

    $invoice = Invoice::query()->latest('created_at')->firstOrFail();

    expect($invoice->isDraft())->toBeTrue();

    Livewire::test(InvoiceEditor::class, ['invoice' => $invoice])->call('issue')->assertHasErrors('invoice');

    expect($invoice->fresh()->isDraft())->toBeTrue();

    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);
    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, true);

    Livewire::test(InvoiceEditor::class, ['invoice' => $invoice->fresh()])->call('issue')->assertHasNoErrors();

    expect($invoice->fresh()->isDraft())->toBeFalse();
});

it('gives the browser the margin check only when there is something to say', function () {
    Livewire::test(InvoiceEditor::class)->assertSeeHtml('data-margin-banner');

    $this->actingAs($this->viewer);
    Livewire::test(InvoiceEditor::class)->assertDontSeeHtml('data-margin-banner');
});
