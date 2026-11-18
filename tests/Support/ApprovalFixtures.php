<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Approval\SaveApprovalPolicy;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaymentBatch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Supplier invoices, payment files and approval policies for the approval
 * engine's tests (ADR-081 E2). Synthetic data only.
 */
final class ApprovalFixtures
{
    public static function supplier(User $owner, Company $company): Supplier
    {
        return app(SaveSupplier::class)($owner, $company, [
            'name' => 'Grossisten Test AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010,
        ]);
    }

    /**
     * A registered invoice for $kronor, prepared by $user.
     *
     * @param  array<string, string>  $row  extra row fields, such as cost_center or project codes
     */
    public static function invoice(User $user, Company $company, Supplier $supplier, string $kronor = '1000', array $row = [], string $number = 'F-1'): SupplierInvoice
    {
        $draft = app(SaveSupplierInvoice::class)($user, $company, [
            'supplier_id' => $supplier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-03-05',
            'total' => $kronor,
            'rows' => [['account' => '', 'amount' => $kronor, ...$row]],
        ]);

        return app(RegisterSupplierInvoice::class)($user, $draft)->fresh();
    }

    /** A payment file of one approved invoice, made by $maker; the owner attests the invoice with the rule off. */
    public static function batch(User $owner, User $maker, Company $company, Supplier $supplier, string $kronor = '1000', string $number = 'B-1'): SupplierPaymentBatch
    {
        $separate = $company->fresh()->separate_supplier_approval;
        $company->forceFill(['separate_supplier_approval' => false])->save();

        if ($company->payment_bank === null) {
            app(SavePaymentBankSettings::class)($owner, $company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);
        }

        $invoice = app(ApproveSupplierInvoice::class)($owner, self::invoice($owner, $company, $supplier, $kronor, number: $number));
        $company->forceFill(['separate_supplier_approval' => $separate])->save();

        return app(CreatePaymentBatch::class)($maker, $company->fresh(), [$invoice->id], CarbonImmutable::parse('2026-03-12'), true);
    }

    /**
     * Saves a policy with the given steps (the page's input form).
     *
     * @param  list<array<string, mixed>>  $steps
     */
    public static function policy(User $owner, Company $company, array $steps, string $type = 'SUPPLIER_INVOICE', string $name = 'Testflöde', int $priority = 10, bool $active = true): ApprovalPolicy
    {
        return app(SaveApprovalPolicy::class)($owner, $company, [
            'subject_type' => $type,
            'name' => $name,
            'priority' => $priority,
            'is_active' => $active,
            'steps' => array_map(fn (array $step): array => [
                'approver_kind' => 'PERMISSION',
                'approver_permission' => $type === 'PAYMENT_BATCH' ? 'payments:approve' : 'supplier-invoices:approve',
                'required_count' => 1,
                'exclude_preparer' => false,
                ...$step,
            ], $steps),
        ]);
    }
}
