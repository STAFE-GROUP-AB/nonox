<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\SupplierPaymentBatch;
use App\Models\Voucher;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionApprovals;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the approval engine knows about a subject: its id, its total in öre,
 * who prepared it and the dimensions, supplier and accounts its conditions
 * can test. A registered supplier invoice and a payment file never change,
 * so the facts read once stay true for the life of a request.
 */
final readonly class SubjectFacts
{
    /**
     * @param  list<int>  $preparerIds  who prepared the subject (excluded from deciding when the step or the company says so)
     * @param  list<int>  $costCenterIds
     * @param  list<int>  $projectIds
     * @param  list<int>  $accountNumbers
     */
    public function __construct(
        public ApprovalSubjectType $type,
        public string $subjectId,
        public int $companyId,
        public int $amountOre,
        public array $preparerIds,
        public ?int $supplierId = null,
        public array $costCenterIds = [],
        public array $projectIds = [],
        public array $accountNumbers = [],
        public bool $amountIncomplete = false,
    ) {}

    /** The person a rejection returns the subject to: the first preparer. */
    public function preparedBy(): ?int
    {
        return $this->preparerIds[0] ?? null;
    }

    /** One query for the rows' dimensions and accounts. */
    public static function forSupplierInvoice(SupplierInvoice $invoice): self
    {
        $rows = SupplierInvoiceRow::query()
            ->where('supplier_invoice_rows.supplier_invoice_id', $invoice->id)
            ->join('accounts', 'accounts.id', '=', 'supplier_invoice_rows.account_id')
            ->toBase()
            ->get(['supplier_invoice_rows.cost_center_id', 'supplier_invoice_rows.project_id', 'accounts.number']);

        $ids = fn (string $column): array => array_values(array_unique(array_map('intval', array_filter($rows->pluck($column)->all(), fn (mixed $id): bool => $id !== null))));

        return new self(
            type: ApprovalSubjectType::SupplierInvoice,
            subjectId: $invoice->id,
            companyId: $invoice->company_id,
            amountOre: $invoice->total_ore->ore,
            preparerIds: self::people([$invoice->registered_by, $invoice->created_by]),
            supplierId: $invoice->supplier_id,
            costCenterIds: $ids('cost_center_id'),
            projectIds: $ids('project_id'),
            accountNumbers: $ids('number'),
        );
    }

    public static function forPaymentBatch(SupplierPaymentBatch $batch): self
    {
        return new self(
            type: ApprovalSubjectType::PaymentBatch,
            subjectId: $batch->id,
            companyId: $batch->company_id,
            amountOre: $batch->total_ore->ore,
            preparerIds: self::people([$batch->created_by]),
        );
    }

    /**
     * A purchase order (ADR-081 E10): its total excluding VAT, its creator, the
     * supplier, and the dimensions and accounts of its rows without an article.
     * A change to the rows starts a new approval round, so the facts read for a
     * request stay true for its life. One query for the accounts' numbers.
     */
    public static function forPurchaseOrder(PurchaseOrder $order): self
    {
        $order->loadMissing('rows');

        $ids = fn (string $column): array => array_values(array_unique(array_map('intval', array_filter($order->rows->pluck($column)->all(), fn (mixed $id): bool => $id !== null))));
        $accountIds = $ids('account_id');

        return new self(
            type: ApprovalSubjectType::PurchaseOrder,
            subjectId: PurchaseOrderApprovals::subjectId($order),
            companyId: $order->company_id,
            amountOre: $order->totalOre(),
            // Everyone who has saved the order, not only its creator and last editor (ADR-081 E21 wiring check).
            preparerIds: self::people([$order->created_by, $order->prepared_by, ...(DraftEditors::of($order->company_id, $order->getMorphClass(), [(string) $order->id], DraftEditors::PURCHASE_ORDER_ACTIONS)[(string) $order->id] ?? [])]),
            supplierId: $order->supplier_id,
            costCenterIds: $ids('cost_center_id'),
            projectIds: $ids('project_id'),
            accountNumbers: $accountIds === [] ? [] : array_values(array_map('intval', Account::query()->whereKey($accountIds)->pluck('number')->all())),
        );
    }

    /**
     * A purchase requisition (ADR-081 E9): the requester's estimate excluding VAT
     * (rows without a price count as nothing), who wrote and last changed it, the
     * supplier when every row names the same one, and the dimensions and accounts
     * of its rows. A requisition that is saved again starts a new approval round,
     * so the facts read for a request stay true for its life. One query for the
     * accounts' numbers and one for the people who saved it.
     */
    public static function forPurchaseRequisition(PurchaseRequisition $requisition): self
    {
        $requisition->loadMissing('rows');

        $ids = fn (string $column): array => array_values(array_unique(array_map('intval', array_filter($requisition->rows->pluck($column)->all(), fn (mixed $id): bool => $id !== null))));
        $accountIds = $ids('account_id');
        $suppliers = $requisition->rows->pluck('supplier_id')->unique()->values()->all();

        return new self(
            type: ApprovalSubjectType::PurchaseRequisition,
            subjectId: PurchaseRequisitionApprovals::subjectId($requisition),
            companyId: $requisition->company_id,
            amountOre: $requisition->estimatedOre(),
            amountIncomplete: $requisition->hasUnpricedRows(),
            // Everyone who has saved it counts as a preparer (ADR-081 E21).
            preparerIds: self::people([$requisition->requested_by, $requisition->prepared_by, ...(DraftEditors::of($requisition->company_id, $requisition->getMorphClass(), [(string) $requisition->id], DraftEditors::REQUISITION_ACTIONS)[(string) $requisition->id] ?? [])]),
            supplierId: count($suppliers) === 1 && $suppliers[0] !== null ? (int) $suppliers[0] : null,
            costCenterIds: $ids('cost_center_id'),
            projectIds: $ids('project_id'),
            accountNumbers: $accountIds === [] ? [] : array_values(array_map('intval', Account::query()->whereKey($accountIds)->pluck('number')->all())),
        );
    }

    /**
     * As forSupplierInvoice for many invoices, in one query (the approval queue).
     *
     * @param  Collection<int, SupplierInvoice>  $invoices
     * @return array<string, self> invoice id => facts
     */
    public static function forSupplierInvoices(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $rows = SupplierInvoiceRow::query()
            ->whereIn('supplier_invoice_rows.supplier_invoice_id', $invoices->pluck('id')->all())
            ->join('accounts', 'accounts.id', '=', 'supplier_invoice_rows.account_id')
            ->toBase()
            ->get(['supplier_invoice_rows.supplier_invoice_id', 'supplier_invoice_rows.cost_center_id', 'supplier_invoice_rows.project_id', 'accounts.number'])
            ->groupBy('supplier_invoice_id');

        $facts = [];

        foreach ($invoices as $invoice) {
            $own = $rows->get($invoice->id, collect());

            $facts[$invoice->id] = new self(
                type: ApprovalSubjectType::SupplierInvoice,
                subjectId: $invoice->id,
                companyId: $invoice->company_id,
                amountOre: $invoice->total_ore->ore,
                preparerIds: self::people([$invoice->registered_by, $invoice->created_by]),
                supplierId: $invoice->supplier_id,
                costCenterIds: self::distinct($own, 'cost_center_id'),
                projectIds: self::distinct($own, 'project_id'),
                accountNumbers: self::distinct($own, 'number'),
            );
        }

        return $facts;
    }

    /**
     * As forPurchaseOrder for many orders with their rows loaded, in two
     * queries (the approval queue).
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     * @return array<string, self> subject id ("{order id}.{round}") => facts
     */
    public static function forPurchaseOrders(int $companyId, Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }

        $accountIds = $orders->flatMap(fn (PurchaseOrder $order): array => $order->rows->pluck('account_id')->filter()->all())->unique()->values()->all();
        $numbers = $accountIds === [] ? [] : array_map('intval', Account::query()->whereKey($accountIds)->pluck('number', 'id')->all());
        $editors = DraftEditors::of($companyId, (new PurchaseOrder)->getMorphClass(), $orders->map(fn (PurchaseOrder $order): string => (string) $order->id)->all(), DraftEditors::PURCHASE_ORDER_ACTIONS);

        $facts = [];

        foreach ($orders as $order) {
            $subjectId = PurchaseOrderApprovals::subjectId($order);

            $facts[$subjectId] = new self(
                type: ApprovalSubjectType::PurchaseOrder,
                subjectId: $subjectId,
                companyId: $order->company_id,
                amountOre: $order->totalOre(),
                preparerIds: self::people([$order->created_by, $order->prepared_by, ...($editors[(string) $order->id] ?? [])]),
                supplierId: $order->supplier_id,
                costCenterIds: self::distinct($order->rows->toBase(), 'cost_center_id'),
                projectIds: self::distinct($order->rows->toBase(), 'project_id'),
                accountNumbers: array_values(array_unique(array_map(fn (mixed $id): int => $numbers[(int) $id] ?? 0, $order->rows->pluck('account_id')->filter()->all()))),
            );
        }

        return $facts;
    }

    /**
     * As forPurchaseRequisition for many requisitions with their rows loaded,
     * in two queries (the approval queue).
     *
     * @param  Collection<int, PurchaseRequisition>  $requisitions
     * @return array<string, self> subject id ("{id}.{round}") => facts
     */
    public static function forPurchaseRequisitions(int $companyId, Collection $requisitions): array
    {
        if ($requisitions->isEmpty()) {
            return [];
        }

        $accountIds = $requisitions->flatMap(fn (PurchaseRequisition $requisition): array => $requisition->rows->pluck('account_id')->filter()->all())->unique()->values()->all();
        $numbers = $accountIds === [] ? [] : array_map('intval', Account::query()->whereKey($accountIds)->pluck('number', 'id')->all());
        $editors = DraftEditors::of($companyId, (new PurchaseRequisition)->getMorphClass(), $requisitions->map(fn (PurchaseRequisition $requisition): string => (string) $requisition->id)->all(), DraftEditors::REQUISITION_ACTIONS);

        $facts = [];

        foreach ($requisitions as $requisition) {
            $subjectId = PurchaseRequisitionApprovals::subjectId($requisition);
            $suppliers = $requisition->rows->pluck('supplier_id')->unique()->values()->all();

            $facts[$subjectId] = new self(
                type: ApprovalSubjectType::PurchaseRequisition,
                subjectId: $subjectId,
                companyId: $requisition->company_id,
                amountOre: $requisition->estimatedOre(),
                preparerIds: self::people([$requisition->requested_by, $requisition->prepared_by, ...($editors[(string) $requisition->id] ?? [])]),
                supplierId: count($suppliers) === 1 && $suppliers[0] !== null ? (int) $suppliers[0] : null,
                costCenterIds: self::distinct($requisition->rows->toBase(), 'cost_center_id'),
                projectIds: self::distinct($requisition->rows->toBase(), 'project_id'),
                accountNumbers: array_values(array_unique(array_map(fn (mixed $id): int => $numbers[(int) $id] ?? 0, $requisition->rows->pluck('account_id')->filter()->all()))),
                amountIncomplete: $requisition->hasUnpricedRows(),
            );
        }

        return $facts;
    }

    /**
     * A manual voucher draft (ADR-081 E21): its debit total, its creator and
     * everyone who has saved it or changed its attachments since, and the
     * dimensions and accounts of its rows. The subject id carries the
     * draft's fingerprint, so the facts belong to the version approved.
     */
    public static function forManualVoucher(Voucher $voucher): self
    {
        $facts = self::forManualVouchers($voucher->company_id, collect([$voucher]), DraftFingerprint::vouchers($voucher->company_id, [$voucher->id]));

        return $facts[$voucher->id] ?? throw new \InvalidArgumentException('The voucher is gone.');
    }

    /**
     * Two queries for any number of drafts: their rows with the accounts'
     * numbers, and their editors in the audit log.
     *
     * @param  Collection<int, Voucher>  $vouchers
     * @param  array<string, string>  $fingerprints  voucher id => fingerprint
     * @return array<string, self> voucher id => facts
     */
    public static function forManualVouchers(int $companyId, Collection $vouchers, array $fingerprints): array
    {
        $ids = $vouchers->map(fn (Voucher $voucher): string => $voucher->id)->values()->all();

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('voucher_rows')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('voucher_rows.company_id', $companyId)
            ->whereIn('voucher_rows.voucher_id', $ids)
            ->get(['voucher_rows.voucher_id', 'voucher_rows.debit_ore', 'voucher_rows.cost_center_id', 'voucher_rows.project_id', 'accounts.number'])
            ->groupBy('voucher_id');
        $editors = DraftEditors::of($companyId, (new Voucher)->getMorphClass(), $ids, DraftEditors::VOUCHER_ACTIONS);

        $facts = [];

        foreach ($vouchers as $voucher) {
            $own = $rows->get($voucher->id, collect());

            $facts[$voucher->id] = new self(
                type: ApprovalSubjectType::ManualVoucher,
                subjectId: DraftFingerprint::subjectId($voucher->id, $fingerprints[$voucher->id] ?? ''),
                companyId: $voucher->company_id,
                amountOre: (int) $own->sum(fn (mixed $row): int => (int) data_get($row, 'debit_ore')),
                preparerIds: self::people([$voucher->created_by, ...($editors[$voucher->id] ?? [])]),
                costCenterIds: self::distinct($own, 'cost_center_id'),
                projectIds: self::distinct($own, 'project_id'),
                accountNumbers: self::distinct($own, 'number'),
            );
        }

        return $facts;
    }

    /**
     * A credit invoice draft (ADR-081 E21): its total including VAT (tested
     * without sign), its creator and everyone who has saved it since, and the
     * dimensions and accounts of its rows.
     */
    public static function forCreditInvoice(Invoice $invoice): self
    {
        $facts = self::forCreditInvoices($invoice->company_id, collect([$invoice]), DraftFingerprint::invoices($invoice->company_id, [$invoice->id]));

        return $facts[$invoice->id] ?? throw new \InvalidArgumentException('The invoice is gone.');
    }

    /**
     * Two queries for any number of drafts.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @param  array<string, string>  $fingerprints  invoice id => fingerprint
     * @return array<string, self> invoice id => facts
     */
    public static function forCreditInvoices(int $companyId, Collection $invoices, array $fingerprints): array
    {
        $ids = $invoices->map(fn (Invoice $invoice): string => $invoice->id)->values()->all();

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('invoice_rows')
            ->leftJoin('accounts', 'accounts.id', '=', 'invoice_rows.account_id')
            ->where('invoice_rows.company_id', $companyId)
            ->whereIn('invoice_rows.invoice_id', $ids)
            ->get(['invoice_rows.invoice_id', 'invoice_rows.cost_center_id', 'invoice_rows.project_id', 'accounts.number'])
            ->groupBy('invoice_id');
        $editors = DraftEditors::of($companyId, (new Invoice)->getMorphClass(), $ids, DraftEditors::INVOICE_ACTIONS);

        $facts = [];

        foreach ($invoices as $invoice) {
            $own = $rows->get($invoice->id, collect());

            $facts[$invoice->id] = new self(
                type: ApprovalSubjectType::CreditInvoice,
                subjectId: DraftFingerprint::subjectId($invoice->id, $fingerprints[$invoice->id] ?? ''),
                companyId: $invoice->company_id,
                amountOre: $invoice->total_ore->ore,
                preparerIds: self::people([$invoice->created_by, ...($editors[$invoice->id] ?? [])]),
                costCenterIds: self::distinct($own, 'cost_center_id'),
                projectIds: self::distinct($own, 'project_id'),
                accountNumbers: self::distinct($own, 'number'),
            );
        }

        return $facts;
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return list<int>
     */
    private static function distinct(Collection $rows, string $column): array
    {
        return array_values(array_unique(array_map('intval', array_filter($rows->pluck($column)->all(), fn (mixed $id): bool => $id !== null))));
    }

    /**
     * @param  list<?int>  $ids
     * @return list<int>
     */
    private static function people(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, fn (?int $id): bool => $id !== null)));
    }
}
