<?php

declare(strict_types=1);

namespace App\Domain\Approval;

/**
 * What an approval policy applies to (ADR-081 gap 8). Supplier invoices and
 * payment files are wired in E2, purchase orders in E10, and manual vouchers
 * and credit invoices in E21; purchase requisitions are wired by their own
 * package (E9) and cannot be chosen until then.
 *
 * Manual vouchers and credit invoices have no approval today: their default
 * policy is "none" (owner decision 19), so a company that never adds a policy
 * for them posts and issues exactly as before. While one applies, the draft
 * stays a draft until it is approved, and is numbered only when it is posted
 * or issued, so the series stay gapless.
 */
enum ApprovalSubjectType: string
{
    case SupplierInvoice = 'SUPPLIER_INVOICE';
    case PaymentBatch = 'PAYMENT_BATCH';
    case PurchaseRequisition = 'PURCHASE_REQUISITION';
    case PurchaseOrder = 'PURCHASE_ORDER';
    case ManualVoucher = 'MANUAL_VOUCHER';
    case CreditInvoice = 'CREDIT_INVOICE';

    public function label(): string
    {
        return match ($this) {
            self::SupplierInvoice => __('Leverantörsfakturor'),
            self::PaymentBatch => __('Betalfiler'),
            self::PurchaseRequisition => __('Inköpsanmodan'),
            self::PurchaseOrder => __('Inköpsorder'),
            self::ManualVoucher => __('Manuella verifikationer'),
            self::CreditInvoice => __('Kreditfakturor'),
        };
    }

    /** Whether the subject's own approval runs through the engine yet. */
    public function isWired(): bool
    {
        return in_array($this, self::wired(), true);
    }

    /** @return list<self> */
    public static function wired(): array
    {
        return [self::SupplierInvoice, self::PaymentBatch, self::PurchaseOrder, self::ManualVoucher, self::CreditInvoice];
    }

    /**
     * The permission the subject's approval needs today, and so the one the
     * default policy names. A purchase order is approved by whoever may send
     * it (purchase-orders:send, the approver role); a manual voucher by whoever
     * may post it and a credit invoice by whoever may credit; a requisition
     * gets its own when it is wired (E9).
     */
    public function basePermission(): string
    {
        return match ($this) {
            self::PaymentBatch => 'payments:approve',
            self::PurchaseOrder => 'purchase-orders:send',
            self::ManualVoucher => 'vouchers:post',
            self::CreditInvoice => 'invoices:credit',
            self::SupplierInvoice, self::PurchaseRequisition => 'supplier-invoices:approve',
        };
    }

    /**
     * Which step conditions mean something for the subject. A payment file
     * holds many invoices, so only its total can be tested; a voucher and a
     * credit invoice have no supplier.
     *
     * @return list<string>
     */
    public function conditionKeys(): array
    {
        return match ($this) {
            self::PaymentBatch => ['min_ore', 'max_ore'],
            self::ManualVoucher, self::CreditInvoice => ['min_ore', 'max_ore', 'cost_center_ids', 'project_ids', 'account_from', 'account_to'],
            default => StepConditions::KEYS,
        };
    }

    /** Whether the subject has an approval of its own when no policy applies (false: it is posted or issued as before). */
    public function hasDefaultApproval(): bool
    {
        return ! in_array($this, [self::ManualVoucher, self::CreditInvoice], true);
    }

    /** @return list<ApproverKind> */
    public function approverKinds(): array
    {
        return match ($this) {
            self::PaymentBatch => [ApproverKind::Permission, ApproverKind::Users],
            default => ApproverKind::cases(),
        };
    }

    /**
     * The permissions a step may name: the subject's own, or the owners and
     * administrators (approvals:manage).
     *
     * @return list<string>
     */
    public function stepPermissions(): array
    {
        return [$this->basePermission(), 'approvals:manage'];
    }
}
