<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Models\Account;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\SupplierPaymentBatch;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;

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
            preparerIds: self::people([$order->created_by, $order->prepared_by]),
            supplierId: $order->supplier_id,
            costCenterIds: $ids('cost_center_id'),
            projectIds: $ids('project_id'),
            accountNumbers: $accountIds === [] ? [] : array_values(array_map('intval', Account::query()->whereKey($accountIds)->pluck('number')->all())),
        );
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
