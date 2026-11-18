<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsPurchaseOrders;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PurchaseMatchAllocation;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Purchasing\Matching\MatchOutcome;
use App\Modules\Inventory\Purchasing\Matching\ThreeWayMatch;
use App\Modules\Inventory\Purchasing\PurchaseOrderMatch;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Says which purchase order row each row of a registered supplier invoice
 * pays for, and for how much of it, in the order row's unit (ADR-081 E11),
 * then compares the invoice again. Only rows of orders the invoice is
 * matched with can be chosen, and only while the invoice waits for attest.
 *
 * suggest() fills in the rows whose amount equals exactly one order row's
 * received or ordered value: a plain rule a person checks, never a choice
 * NONOX makes on its own beyond that.
 */
class AllocateSupplierInvoiceRows
{
    use GuardsPurchaseOrders;

    public function __construct(private readonly RecomputePurchaseMatch $recompute) {}

    /**
     * @param  array<int|string, array{order_row?: int|string|null, quantity?: string|null}>  $rows  by invoice row id
     */
    public function save(User $user, Company $company, SupplierInvoice $invoice, array $rows): MatchOutcome
    {
        $invoice = $this->authorize($user, $company, $invoice);

        return CurrentCompany::run($company, fn (): MatchOutcome => DB::transaction(function () use ($user, $company, $invoice, $rows): MatchOutcome {
            [$invoice, $invoiceRows, $orderRows, $existing] = $this->load($invoice);
            $allocated = 0;
            $cleared = 0;

            foreach ($rows as $invoiceRowId => $input) {
                $invoiceRow = $invoiceRows->get((int) $invoiceRowId) ?? throw ValidationException::withMessages(['allocations' => __('Raden finns inte på fakturan.')]);
                $orderRowId = (int) ($input['order_row'] ?? 0);
                $current = $existing->get($invoiceRow->id);

                if ($orderRowId === 0) {
                    if ($current instanceof PurchaseMatchAllocation) {
                        $current->delete();
                        $cleared++;
                    }

                    continue;
                }

                $orderRow = $orderRows->get($orderRowId) ?? throw ValidationException::withMessages(["allocations.{$invoiceRow->id}.order_row" => __('Välj en rad på en inköpsorder som är kopplad till fakturan.')]);
                $milli = $this->quantity($invoiceRow, $orderRow, (string) ($input['quantity'] ?? ''));

                if ($current instanceof PurchaseMatchAllocation) {
                    if ($current->purchase_order_row_id === $orderRow->id && $current->invoiced_milli === $milli) {
                        continue;
                    }

                    $current->update(['purchase_order_row_id' => $orderRow->id, 'invoiced_milli' => $milli, 'allocated_by' => $user->id]);
                } else {
                    PurchaseMatchAllocation::create([
                        'supplier_invoice_id' => $invoice->id,
                        'supplier_invoice_row_id' => $invoiceRow->id,
                        'purchase_order_row_id' => $orderRow->id,
                        'invoiced_milli' => $milli,
                        'allocated_by' => $user->id,
                    ]);
                }

                $allocated++;
            }

            if ($allocated + $cleared > 0) {
                Audit::record('inventory.purchase_match_allocated', $invoice, ['supplier_invoice_id' => $invoice->id, 'allocated' => $allocated, 'cleared' => $cleared], $company, $user);
            }

            return ($this->recompute)($company, $invoice, $user);
        }));
    }

    /**
     * Allocates every row not yet allocated whose amount equals the received
     * value, or else the ordered value, of exactly one order row nothing on
     * the invoice is allocated to yet. Returns how many rows it filled in.
     */
    public function suggest(User $user, Company $company, SupplierInvoice $invoice): int
    {
        $invoice = $this->authorize($user, $company, $invoice);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $invoice): int {
            [, $invoiceRows, $orderRows, $existing] = $this->load($invoice);
            $taken = $existing->pluck('purchase_order_row_id')->all();
            $proposal = [];

            foreach ($invoiceRows as $invoiceRow) {
                if ($existing->has($invoiceRow->id)) {
                    continue;
                }

                $amount = $invoiceRow->amount_ore->ore;
                $free = $orderRows->reject(fn (PurchaseOrderRow $row): bool => in_array($row->id, $taken, true));

                foreach ([
                    [fn (PurchaseOrderRow $row): bool => PurchaseOrderMatch::receivedValueOre($row) === $amount, fn (PurchaseOrderRow $row): int => ThreeWayMatch::receivedMilli($row)],
                    [fn (PurchaseOrderRow $row): bool => $row->valueOre() === $amount, fn (PurchaseOrderRow $row): int => ThreeWayMatch::orderedMilli($row)],
                ] as [$matches, $quantity]) {
                    $candidates = $free->filter($matches);

                    $row = $candidates->first();

                    if ($candidates->count() === 1 && $amount > 0 && $row instanceof PurchaseOrderRow) {
                        $taken[] = $row->id;
                        $proposal[$invoiceRow->id] = ['order_row' => $row->id, 'quantity' => Quantity::decimal($quantity($row))];

                        break;
                    }
                }
            }

            if ($proposal !== []) {
                $this->save($user, $company, $invoice, $proposal);
            }

            return count($proposal);
        }));
    }

    /** The invoice inside the company (another company's answers 404), seen by the user. */
    private function authorize(User $user, Company $company, SupplierInvoice $invoice): SupplierInvoice
    {
        $this->guard($user, $company, 'manage');
        $invoice = CurrentCompany::run($company, fn (): SupplierInvoice => SupplierInvoice::query()->findOrFail($invoice->id));
        Gate::forUser($user)->authorize('view', $invoice);

        return $invoice;
    }

    /**
     * The invoice (locked), its rows, the rows of the orders it is matched
     * with, and its allocations, all inside the company.
     *
     * @return array{0: SupplierInvoice, 1: Collection<int, SupplierInvoiceRow>, 2: Collection<int, PurchaseOrderRow>, 3: Collection<int, PurchaseMatchAllocation>}
     */
    private function load(SupplierInvoice $invoice): array
    {
        /** @var SupplierInvoice $invoice */
        $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        if ($invoice->isCredit()) {
            throw ValidationException::withMessages(['allocations' => __('En kreditnota stäms inte av mot inköpsorder.')]);
        }

        if ($invoice->status !== SupplierInvoiceStatus::Registered) {
            throw ValidationException::withMessages(['allocations' => __('Fakturan väntar inte på attest. Avstämningen sparas som den var när fakturan attesterades.')]);
        }

        $orders = PurchaseOrder::query()
            ->whereIn('id', fn ($query) => $query->select('purchase_order_id')->from('purchase_order_invoices')->where('supplier_invoice_id', $invoice->id))
            ->with('rows')
            ->get();

        return [
            $invoice,
            SupplierInvoiceRow::query()->where('supplier_invoice_id', $invoice->id)->orderBy('position')->orderBy('id')->get()->keyBy('id'),
            $orders->flatMap(fn (PurchaseOrder $order): Collection => $order->rows)->keyBy('id'),
            PurchaseMatchAllocation::query()->where('supplier_invoice_id', $invoice->id)->get()->keyBy('supplier_invoice_row_id'),
        ];
    }

    /** The invoiced quantity as typed, or what has been received (else ordered) when nothing is typed. */
    private function quantity(SupplierInvoiceRow $invoiceRow, PurchaseOrderRow $orderRow, string $typed): int
    {
        if (trim($typed) === '') {
            $received = ThreeWayMatch::receivedMilli($orderRow);

            return $received > 0 ? $received : ThreeWayMatch::orderedMilli($orderRow);
        }

        try {
            $milli = Quantity::parse($typed);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(["allocations.{$invoiceRow->id}.quantity" => __('Antalet går inte att läsa. Skriv till exempel 12 eller 2,5.')]);
        }

        if ($milli < 0 || $milli > 1_000_000_000_000) {
            throw ValidationException::withMessages(["allocations.{$invoiceRow->id}.quantity" => __('Antalet kan inte vara negativt.')]);
        }

        return $milli;
    }
}
