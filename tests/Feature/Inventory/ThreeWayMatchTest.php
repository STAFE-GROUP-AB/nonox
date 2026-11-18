<?php

declare(strict_types=1);

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Payables\SupplierInvoiceActions;
use App\Models\AuditLog;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Inventory\Actions\Purchasing\AllocateSupplierInvoiceRows;
use App\Modules\Inventory\Actions\Purchasing\ConfirmOrderRowDelivery;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\OverridePurchaseMatch;
use App\Modules\Inventory\Actions\Purchasing\PurchaseOrderLifecycle;
use App\Modules\Inventory\Actions\Purchasing\RecomputePurchaseMatch;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Livewire\InventorySettingsForm;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderMatchPanel;
use App\Modules\Inventory\Models\PurchaseMatchAllocation;
use App\Modules\Inventory\Models\PurchaseMatchResult;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Matching\MatchReason;
use App\Modules\Inventory\Purchasing\Matching\MatchStatus;
use App\Modules\Inventory\Purchasing\Matching\MatchTolerances;
use App\Modules\Inventory\Purchasing\Matching\PurchaseMatchAttest;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * ADR-081 E11: three-way matching per row. Each supplier invoice row is
 * allocated to the purchase order row it pays for; the stored result per row
 * compares what was ordered, what the goods receipts (or the delivery
 * confirmation) say arrived and what is invoiced, against the company's
 * tolerances (owner decision 11: quantity 0 %, price 2 % or 50 kr). By
 * default a deviation only warns at the attest (decision 10); with the
 * company setting it blocks until someone with attest rights overrides it
 * with a reason. Nothing is booked.
 */

beforeEach(function () {
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->supplier = Purchasing::supplier($this->owner, $this->company);
    $this->screw = Purchasing::screw($this->owner, $this->company, $this->supplier, ['default_location_id' => $this->places['bin2']->id]);
    $this->bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->approver = Bookkeeping::member($this->company, 'approver');
    $this->admin = Bookkeeping::member($this->company, 'admin');
    $this->company = $this->company->fresh();
});

/** A sent order: three packs of screws at 189 kr (567 kr) and a lift hired for two days at 2 000 kr (4 000 kr). */
function e11Order(object $test): PurchaseOrder
{
    $order = Purchasing::order($test->bookkeeper, $test->company, $test->supplier, [
        ['article_id' => $test->screw->id, 'quantity' => '3', 'price' => '189'],
        ['description' => 'Hyra av lift, 2 dagar', 'account' => '4010', 'quantity' => '2', 'price' => '2000'],
    ]);
    app(SendPurchaseOrder::class)->printed($test->bookkeeper, $test->company, $order);

    return $order->fresh(['rows']);
}

/** Receives $screws (stock units, 200 per pack) of the order's screws. */
function e11Receive(object $test, PurchaseOrder $order, string $screws): void
{
    $receipt = app(SaveReceipt::class)->fromPurchaseOrder($test->bookkeeper, $test->company, $order->fresh());
    $line = $receipt->lines()->sole();
    app(ReceiveStock::class)($test->bookkeeper, $test->company, $receipt, [$line->id => ['quantity' => $screws]], CarbonImmutable::parse('2026-07-01'));
}

function e11Confirm(object $test, PurchaseOrder $order): void
{
    app(ConfirmOrderRowDelivery::class)($test->bookkeeper, $test->company, $order->fresh(), $order->rows->last()->id);
}

/**
 * A registered invoice from the supplier with the given rows (amount excluding VAT, description), matched with the order.
 *
 * @param  list<array{0: string, 1: string}>  $rows
 */
function e11Invoice(object $test, PurchaseOrder $order, array $rows, string $number = 'F-2001'): SupplierInvoice
{
    $net = array_sum(array_map(fn (array $row): int => Money::parse($row[0])->ore, $rows));
    $vat = intdiv($net, 4);
    $kr = fn (int $ore): string => Money::ofOre($ore)->format(withCurrency: false);

    $invoice = app(SaveSupplierInvoice::class)($test->bookkeeper, $test->company, [
        'supplier_id' => $test->supplier->id,
        'invoice_number' => $number,
        'invoice_date' => '2026-07-10',
        'total' => $kr($net + $vat),
        'vat' => $kr($vat),
        'rows' => array_map(fn (array $row): array => ['account' => '4010', 'amount' => $row[0], 'description' => $row[1]], $rows),
    ]);
    $invoice = app(RegisterSupplierInvoice::class)($test->bookkeeper, $invoice);
    app(MatchSupplierInvoice::class)->link($test->bookkeeper, $test->company, $order, $invoice);

    return $invoice->fresh();
}

/** @param  array<int, array{0: int, 1: string}>  $map  invoice row position (0-based) → [order row position, quantity] */
function e11Allocate(object $test, SupplierInvoice $invoice, PurchaseOrder $order, array $map, ?User $by = null): void
{
    $invoiceRows = $invoice->rows()->get()->values();
    $orderRows = $order->rows->values();
    $input = [];

    foreach ($map as $position => [$orderRow, $quantity]) {
        $input[$invoiceRows[$position]->id] = ['order_row' => $orderRows[$orderRow]->id, 'quantity' => $quantity];
    }

    app(AllocateSupplierInvoiceRows::class)->save($by ?? $test->bookkeeper, $test->company, $invoice, $input);
}

/** @return list<string> the current results' statuses, in order */
function e11Statuses(SupplierInvoice $invoice): array
{
    return RecomputePurchaseMatch::current($invoice)->map(fn (PurchaseMatchResult $result): string => $result->status->value)->all();
}

function e11Blocking(object $test, bool $blocks = true): void
{
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $test->company, $test->owner, ['match_outside_blocks_attest' => $blocks]);
}

it('matches each invoice row with its order row and receipt, lets freight through, and attests without a voucher', function () {
    $order = e11Order($this);
    e11Receive($this, $order, '600');
    e11Confirm($this, $order);
    $invoice = e11Invoice($this, $order, [['567', 'Skruv'], ['4 000', 'Lift'], ['30', 'Frakt']]);

    expect(e11Statuses($invoice))->toBe(['UNMATCHED', 'UNMATCHED', 'UNMATCHED']);

    $filled = app(AllocateSupplierInvoiceRows::class)->suggest($this->bookkeeper, $this->company, $invoice);
    $results = RecomputePurchaseMatch::current($invoice);
    [$screw, $lift, $freight] = $results->all();

    expect($filled)->toBe(2)
        ->and($results->pluck('status')->all())->toBe([MatchStatus::Within, MatchStatus::Within, MatchStatus::Within])
        // In the row's unit: three packs ordered, three received, three invoiced.
        ->and([$screw->ordered_milli, $screw->received_milli, $screw->invoiced_milli, $screw->expected_ore, $screw->price_diff_ore])->toBe([3000, 3000, 3000, 56700, 0])
        ->and($screw->stock_receipt_line_id)->not->toBeNull()
        ->and([$lift->received_milli, $lift->invoiced_ore, $lift->stock_receipt_line_id])->toBe([2000, 400000, null])
        ->and($freight->purchase_order_row_id)->toBeNull()
        ->and($freight->reasons)->toBe([MatchReason::SmallWithoutOrderRow->value])
        ->and([$screw->quantity_tolerance_bp, $screw->price_tolerance_bp, $screw->absolute_tolerance_ore])->toBe([0, 200, 5000]);

    $vouchers = Voucher::query()->count();
    app(ApproveSupplierInvoice::class)($this->approver, $invoice->fresh());

    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(Voucher::query()->count())->toBe($vouchers)
        ->and(AuditLog::query()->where('action', 'inventory.purchase_match_attested_with_deviations')->exists())->toBeFalse();
});

it('flags an invoice charging for more than arrived and above the price, and by default warns but lets the attest through', function () {
    $order = e11Order($this);
    e11Receive($this, $order, '400');
    e11Confirm($this, $order);
    $invoice = e11Invoice($this, $order, [['600', 'Skruv'], ['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [0, '3'], 1 => [1, '2']]);

    [$screw, $lift] = RecomputePurchaseMatch::current($invoice)->all();

    // Two of three packs arrived; three are invoiced at 200 kr instead of 189 kr (33 kr over, inside 50 kr).
    expect($screw->status)->toBe(MatchStatus::Outside)
        ->and($screw->reasons)->toBe([MatchReason::QuantityOver->value])
        ->and([$screw->received_milli, $screw->quantity_diff_milli, $screw->price_diff_ore])->toBe([2000, 1000, 3300])
        ->and($lift->status)->toBe(MatchStatus::Within);

    app(ApproveSupplierInvoice::class)($this->approver, $invoice->fresh());

    $entry = AuditLog::query()->where('action', 'inventory.purchase_match_attested_with_deviations')->sole();

    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and($entry->payload)->toEqual(['supplier_invoice_id' => $invoice->id, 'open' => 1]);
});

it('blocks the attest by the company setting until someone with attest rights overrides the deviation with a reason', function () {
    e11Blocking($this);
    $order = e11Order($this);
    e11Receive($this, $order, '600');
    e11Confirm($this, $order);
    $invoice = e11Invoice($this, $order, [['700', 'Skruv'], ['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [0, '3'], 1 => [1, '2']]);
    $screw = RecomputePurchaseMatch::current($invoice)->first();

    // 700 kr against 567 kr: 133 kr over, more than 2 % (11,34 kr) and more than 50 kr.
    expect($screw->reasons)->toBe([MatchReason::PriceOver->value])
        ->and(fn () => app(ApproveSupplierInvoice::class)($this->approver, $invoice->fresh()))->toThrow(ValidationException::class, 'utanför toleranserna')
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Registered)
        ->and(fn () => app(OverridePurchaseMatch::class)($this->bookkeeper, $this->company, $invoice, $screw->id, 'Prishöjning enligt avtal'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(OverridePurchaseMatch::class)($this->approver, $this->company, $invoice, $screw->id, ' '))->toThrow(ValidationException::class);

    app(OverridePurchaseMatch::class)($this->approver, $this->company, $invoice, $screw->id, 'Prishöjning enligt avtal');
    $screw = $screw->fresh();

    expect($screw->status)->toBe(MatchStatus::Overridden)
        ->and($screw->override_reason)->toBe('Prishöjning enligt avtal')
        ->and($screw->overridden_by)->toBe($this->approver->id)
        ->and(fn () => app(OverridePurchaseMatch::class)($this->approver, $this->company, $invoice, $screw->id, 'Igen'))->toThrow(ValidationException::class, 'avviker inte');

    $entry = AuditLog::query()->where('action', 'inventory.purchase_match_overridden')->sole();
    expect($entry->payload)->toEqual(['supplier_invoice_id' => $invoice->id, 'result_id' => $screw->id, 'from' => 'OUTSIDE'])
        ->and(json_encode($entry->payload))->not->toContain('avtal');

    app(ApproveSupplierInvoice::class)($this->approver, $invoice->fresh());
    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('treats an unconfirmed service and goods not received as outside, and carries an override only while nothing changed', function () {
    $order = e11Order($this);
    $invoice = e11Invoice($this, $order, [['567', 'Skruv'], ['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [0, '3'], 1 => [1, '2']]);
    [$screw, $lift] = RecomputePurchaseMatch::current($invoice)->all();

    expect($screw->reasons)->toBe([MatchReason::NotReceived->value])
        ->and($lift->reasons)->toBe([MatchReason::NotConfirmed->value]);

    app(OverridePurchaseMatch::class)($this->approver, $this->company, $invoice, $screw->id, 'Varorna står på kajen');
    e11Confirm($this, $order);
    app(RecomputePurchaseMatch::class)($this->company, $invoice, $this->bookkeeper);
    [$screwAgain, $liftAgain] = RecomputePurchaseMatch::current($invoice)->all();

    expect($screwAgain->id)->not->toBe($screw->id)
        ->and($screwAgain->status)->toBe(MatchStatus::Overridden)
        ->and($screwAgain->carried_from_id)->toBe($screw->id)
        ->and($liftAgain->status)->toBe(MatchStatus::Within)
        ->and($screw->fresh()->superseded_at)->not->toBeNull();

    // The goods arrive: the figures of the overridden row change, so the override does not follow.
    e11Receive($this, $order, '600');
    app(RecomputePurchaseMatch::class)($this->company, $invoice, $this->bookkeeper);

    expect(e11Statuses($invoice))->toBe(['WITHIN', 'WITHIN'])
        ->and(RecomputePurchaseMatch::current($invoice)->first()->carried_from_id)->toBeNull()
        ->and(fn () => app(OverridePurchaseMatch::class)($this->approver, $this->company, $invoice, $screwAgain->id, 'Gammal'))->toThrow(ValidationException::class, 'ändrats');
});

it('refuses an attest on a comparison that changed since the page showed it', function () {
    $order = e11Order($this);
    e11Receive($this, $order, '200');
    $invoice = e11Invoice($this, $order, [['567', 'Skruv'], ['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [0, '3'], 1 => [1, '2']]);
    $seen = app(PurchaseMatchAttest::class)->seen($this->company, $invoice);

    // The lift's delivery is confirmed after the page was opened: the screws still deviate, in a new comparison.
    e11Confirm($this, $order);

    expect(fn () => app(ApproveSupplierInvoice::class)($this->approver, $invoice->fresh(), $seen))->toThrow(ValidationException::class, 'ändrats sedan den visades');

    $seen = app(PurchaseMatchAttest::class)->seen($this->company, $invoice);
    app(ApproveSupplierInvoice::class)($this->approver, $invoice->fresh(), $seen);

    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('keeps the person who allocated the rows out of the attest and the override while separate approval is on', function () {
    e11Blocking($this);
    $order = e11Order($this);
    e11Receive($this, $order, '600');
    e11Confirm($this, $order);
    $invoice = e11Invoice($this, $order, [['700', 'Skruv'], ['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [0, '3'], 1 => [1, '2']], $this->admin);
    $screw = RecomputePurchaseMatch::current($invoice)->first();

    expect(fn () => app(OverridePurchaseMatch::class)($this->admin, $this->company, $invoice, $screw->id, 'Eget skäl'))->toThrow(ValidationException::class, 'förberett')
        ->and(fn () => app(ApproveSupplierInvoice::class)($this->admin, $invoice->fresh()))->toThrow(ValidationException::class, 'fördelade');

    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    app(OverridePurchaseMatch::class)($this->admin, $this->company->fresh(), $invoice, $screw->id, 'Avtalat pris');
    app(ApproveSupplierInvoice::class)($this->admin, $invoice->fresh());

    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('applies the hook on every step of the company\'s own approval flow', function () {
    e11Blocking($this);
    ApprovalFixtures::policy($this->owner, $this->company, [['required_count' => 1]]);
    $order = e11Order($this);
    e11Receive($this, $order, '600');
    e11Confirm($this, $order);
    $invoice = e11Invoice($this, $order, [['700', 'Skruv'], ['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [0, '3'], 1 => [1, '2']]);

    expect(fn () => app(ApproveSupplierInvoice::class)($this->approver, $invoice->fresh()))->toThrow(ValidationException::class, 'utanför toleranserna')
        ->and(DB::table('approval_decisions')->count())->toBe(0);

    app(OverridePurchaseMatch::class)($this->approver, $this->company, $invoice, RecomputePurchaseMatch::current($invoice)->first()->id, 'Avtalat pris');
    app(ApproveSupplierInvoice::class)($this->approver, $invoice->fresh());

    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(DB::table('approval_decisions')->count())->toBe(1);
});

it('counts what earlier invoices charged on the same order row', function () {
    $order = e11Order($this);
    e11Receive($this, $order, '400');
    e11Confirm($this, $order);
    $first = e11Invoice($this, $order, [['378', 'Skruv']], 'F-1');
    e11Allocate($this, $first, $order, [0 => [0, '2']]);
    $second = e11Invoice($this, $order, [['189', 'Skruv']], 'F-2');
    e11Allocate($this, $second, $order, [0 => [0, '1']]);

    $result = RecomputePurchaseMatch::current($second)->sole();

    expect(e11Statuses($first))->toBe(['WITHIN'])
        ->and([$result->previously_invoiced_milli, $result->quantity_diff_milli])->toBe([2000, 1000])
        ->and($result->reasons)->toBe([MatchReason::QuantityOver->value]);
});

it('compares only registered invoices, never credit notes or invoices without Lager, and freezes the result at attest', function () {
    $order = e11Order($this);
    e11Receive($this, $order, '600');
    e11Confirm($this, $order);
    $invoice = e11Invoice($this, $order, [['567', 'Skruv'], ['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [0, '3'], 1 => [1, '2']]);
    app(ApproveSupplierInvoice::class)($this->approver, $invoice->fresh());
    $ids = RecomputePurchaseMatch::current($invoice)->pluck('id')->all();

    expect(fn () => e11Allocate($this, $invoice->fresh(), $order, [0 => [0, '1']]))->toThrow(ValidationException::class, 'väntar inte på attest')
        ->and(fn () => app(MatchSupplierInvoice::class)->unlink($this->bookkeeper, $this->company, $order, $invoice->fresh()))->toThrow(ValidationException::class, 'attesterad');

    app(RecomputePurchaseMatch::class)($this->company, $invoice->fresh());
    expect(RecomputePurchaseMatch::current($invoice)->pluck('id')->all())->toBe($ids);

    $draft = Purchasing::invoice($this->bookkeeper, $this->company, $this->supplier, '100', 'F-9');
    expect(RecomputePurchaseMatch::compares($this->company, $draft))->toBeFalse();

    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);
    expect(RecomputePurchaseMatch::compares($this->company->fresh(), $invoice->fresh()))->toBeFalse();
});

it('drops the order\'s allocations when the order is unlinked, and keeps an allocated order row from being removed', function () {
    $order = e11Order($this);
    $invoice = e11Invoice($this, $order, [['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [1, '2']]);
    $revised = app(PurchaseOrderLifecycle::class)->revise($this->bookkeeper, $this->company, $order->fresh());

    expect(fn () => app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $order->rows[0]->id, 'article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189']]], $revised))->toThrow(ValidationException::class, 'avstämd');

    // The lift is not confirmed, so the invoice deviates: only someone allowed to attest may unlink it.
    expect(fn () => app(MatchSupplierInvoice::class)->unlink($this->bookkeeper, $this->company, $order, $invoice))->toThrow(ValidationException::class, 'Bara den som får attestera')
        ->and(PurchaseMatchAllocation::query()->count())->toBe(1);

    app(MatchSupplierInvoice::class)->unlink($this->owner, $this->company, $order, $invoice);

    expect(PurchaseMatchAllocation::query()->count())->toBe(0)
        ->and(RecomputePurchaseMatch::current($invoice))->toBeEmpty()
        ->and(PurchaseMatchResult::query()->whereNotNull('superseded_at')->count())->toBeGreaterThan(0);
});

it('judges an unlink on a fresh comparison, made under the invoice lock', function () {
    $order = e11Order($this);
    e11Receive($this, $order, '600');
    e11Confirm($this, $order);
    $invoice = e11Invoice($this, $order, [['567', 'Skruv'], ['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [0, '3'], 1 => [1, '2']]);

    expect(e11Statuses($invoice))->toBe(['WITHIN', 'WITHIN']);

    // The stored comparison is clean, but the lift's confirmation is withdrawn afterwards: the order row now deviates.
    app(ConfirmOrderRowDelivery::class)($this->bookkeeper, $this->company, $order->fresh(), $order->rows->last()->id, false);

    expect(e11Statuses($invoice))->toBe(['WITHIN', 'WITHIN'])
        ->and(fn () => app(MatchSupplierInvoice::class)->unlink($this->bookkeeper, $this->company, $order, $invoice))->toThrow(ValidationException::class, 'Bara den som får attestera')
        ->and(PurchaseMatchAllocation::query()->count())->toBe(2);
});

it('keeps the allocator from unlinking a deviating invoice under separate approval, even with attest rights', function () {
    $order = e11Order($this);
    $invoice = e11Invoice($this, $order, [['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [1, '2']], $this->admin);

    expect(fn () => app(MatchSupplierInvoice::class)->unlink($this->admin, $this->company, $order, $invoice))->toThrow(ValidationException::class, 'du fördelade dess rader')
        ->and(PurchaseMatchAllocation::query()->count())->toBe(1);

    app(MatchSupplierInvoice::class)->unlink($this->owner, $this->company, $order, $invoice);

    expect(PurchaseMatchAllocation::query()->count())->toBe(0);
});

it('refuses to delete or edit a stored result, and allows only the superseding and one override', function () {
    $order = e11Order($this);
    $invoice = e11Invoice($this, $order, [['567', 'Skruv']]);
    $result = RecomputePurchaseMatch::current($invoice)->sole();

    expect(fn () => DB::transaction(fn () => DB::table('purchase_match_results')->where('id', $result->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('purchase_match_results')->where('id', $result->id)->update(['invoiced_ore' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('purchase_match_results')->where('id', $result->id)->update(['status' => 'WITHIN'])))->toThrow(QueryException::class)
        ->and(fn () => $result->delete())->toThrow(LogicException::class);

    DB::table('purchase_match_results')->where('id', $result->id)->update(['status' => 'OVERRIDDEN', 'override_reason' => 'Skäl', 'overridden_by' => $this->approver->id, 'overridden_at' => now()]);

    expect(fn () => DB::transaction(fn () => DB::table('purchase_match_results')->where('id', $result->id)->update(['override_reason' => 'Annat skäl'])))->toThrow(QueryException::class);

    DB::table('purchase_match_results')->where('id', $result->id)->update(['superseded_at' => now()]);

    expect(fn () => DB::transaction(fn () => DB::table('purchase_match_results')->where('id', $result->id)->update(['superseded_at' => null])))->toThrow(QueryException::class);
});

it('reads the tolerances as owner decision 11 sets them and lets the company change them', function () {
    $tolerances = MatchTolerances::for($this->company);

    expect([$tolerances->quantityBp, $tolerances->priceBp, $tolerances->absoluteOre, $tolerances->blocksAttest])->toBe([0, 200, 5000, false])
        ->and($tolerances->priceAllowance(1_000_000))->toBe(20000)
        ->and($tolerances->priceAllowance(100_000))->toBe(5000)
        ->and($tolerances->quantityAllowance(3000))->toBe(0)
        ->and(MatchTolerances::parsePercent('2,5'))->toBe(250)
        ->and(MatchTolerances::parsePercent('101'))->toBeNull()
        ->and(MatchTolerances::parsePercent('tre'))->toBeNull()
        ->and(MatchTolerances::percent(250))->toBe('2,5');

    Livewire::test(InventorySettingsForm::class, ['company' => $this->company])
        ->set('settings.match_quantity_tolerance', 'mycket')
        ->call('save')
        ->assertHasErrors('settings.match_quantity_tolerance');

    Livewire::test(InventorySettingsForm::class, ['company' => $this->company])
        ->assertSet('settings.match_quantity_tolerance', '0')
        ->assertSet('settings.match_price_tolerance', '2')
        ->set('settings.match_quantity_tolerance', '5')
        ->set('settings.match_price_tolerance', '1,5')
        ->set('settings.match_absolute_tolerance', '100')
        ->set('settings.match_outside_blocks_attest', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $tolerances = MatchTolerances::for($this->company);

    expect([$tolerances->quantityBp, $tolerances->priceBp, $tolerances->absoluteOre, $tolerances->blocksAttest])->toBe([500, 150, 10000, true])
        ->and($tolerances->quantityAllowance(3000))->toBe(150);
});

it('lets only those who may change the attest rule change the matching settings', function () {
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $this->actingAs($accountant);

    Livewire::test(InventorySettingsForm::class, ['company' => $this->company])
        ->assertSee('Bara ägare och administratörer kan ändra avstämningen')
        ->set('settings.match_outside_blocks_attest', true)
        ->call('save')
        ->assertHasErrors('settings.match_outside_blocks_attest');

    expect(MatchTolerances::for($this->company)->blocksAttest)->toBeFalse();

    // Other settings still save when the matching is left as it is.
    Livewire::test(InventorySettingsForm::class, ['company' => $this->company])
        ->set('settings.allow_negative_stock', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->actingAs($this->admin);
    Livewire::test(InventorySettingsForm::class, ['company' => $this->company])
        ->assertDontSee('Bara ägare och administratörer kan ändra avstämningen')
        ->set('settings.match_outside_blocks_attest', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(MatchTolerances::for($this->company)->blocksAttest)->toBeTrue();
});

it('lets an approver in the current step of the company\'s flow override, and hides the attest from the allocator', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->approver->id]]]);
    $other = Bookkeeping::member($this->company, 'approver');
    $order = e11Order($this);
    e11Receive($this, $order, '600');
    e11Confirm($this, $order);
    $invoice = e11Invoice($this, $order, [['700', 'Skruv'], ['4 000', 'Lift']]);
    e11Allocate($this, $invoice, $order, [0 => [0, '3'], 1 => [1, '2']], $this->admin);
    $screw = RecomputePurchaseMatch::current($invoice)->first();

    expect(fn () => app(OverridePurchaseMatch::class)($other, $this->company, $invoice, $screw->id, 'Avtalat pris'))->toThrow(AuthorizationException::class);

    app(OverridePurchaseMatch::class)($this->approver, $this->company, $invoice, $screw->id, 'Avtalat pris');
    expect($screw->fresh()->status)->toBe(MatchStatus::Overridden);

    $this->actingAs($this->admin);
    Livewire::test(SupplierInvoiceActions::class, ['invoice' => $invoice->fresh()])
        ->assertDontSee('Attestera fakturan');
});

it('shows the comparison on the invoice page, saves the allocation and the override with toasts, and updates the attest card', function () {
    e11Blocking($this);
    $order = e11Order($this);
    e11Receive($this, $order, '600');
    e11Confirm($this, $order);
    $invoice = e11Invoice($this, $order, [['700', 'Skruv'], ['4 000', 'Lift']]);
    [$screwRow, $liftRow] = $invoice->rows()->get()->all();

    $this->actingAs($this->bookkeeper);
    Livewire::test(PurchaseOrderMatchPanel::class, ['invoiceId' => $invoice->id])
        ->assertSee('Avstämning per rad')
        ->assertSee('Fakturaraden hör inte till någon orderrad.')
        ->set("allocations.{$screwRow->id}.order_row", (string) $order->rows[0]->id)
        ->set("allocations.{$screwRow->id}.quantity", 'många')
        ->call('saveAllocations')
        ->assertHasErrors("allocations.{$screwRow->id}.quantity")
        ->set("allocations.{$screwRow->id}.quantity", '3')
        ->set("allocations.{$liftRow->id}.order_row", (string) $order->rows[1]->id)
        ->call('saveAllocations')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertDispatched(PurchaseOrderMatchPanel::UPDATED_EVENT)
        ->assertSee('Priset är högre än i inköpsordern.')
        ->assertDontSee('Skäl till att avvikelsen godkänns');

    expect(PurchaseMatchAllocation::query()->count())->toBe(2);

    $this->actingAs($this->approver);
    $actions = Livewire::test(SupplierInvoiceActions::class, ['invoice' => $invoice->fresh()])
        ->assertSee('En rad avviker från inköpsordern utanför toleranserna.')
        ->call('approve')
        ->assertHasErrors('approve');

    $screw = RecomputePurchaseMatch::current($invoice)->first();
    $panel = Livewire::test(PurchaseOrderMatchPanel::class, ['invoiceId' => $invoice->id])
        ->call('startOverride', $screw->id)
        ->assertSee('Skäl till att avvikelsen godkänns')
        ->set('overrideReason', 'Avtalat pris')
        ->call('override')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSee('Godkänd av');

    $actions->dispatch(PurchaseOrderMatchPanel::UPDATED_EVENT)
        ->assertDontSee('En rad avviker från inköpsordern utanför toleranserna.')
        ->call('approve')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('renders the panel in a fixed number of queries, whatever the number of rows', function () {
    $count = function (int $rows): int {
        $articles = [];

        for ($i = 0; $i < $rows; $i++) {
            $articles[] = ['article_id' => $this->screw->id, 'quantity' => '1', 'price' => '189'];
        }

        $order = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, $articles);
        app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order);
        $order = $order->fresh(['rows']);
        $invoice = e11Invoice($this, $order, array_fill(0, $rows, ['189', 'Skruv']), 'Q-'.$rows);
        $map = [];

        for ($i = 0; $i < $rows; $i++) {
            $map[$i] = [$i, '1'];
        }

        e11Allocate($this, $invoice, $order, $map);
        $component = Livewire::test(PurchaseOrderMatchPanel::class, ['invoiceId' => $invoice->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(1))->toBe($count(12));
});
