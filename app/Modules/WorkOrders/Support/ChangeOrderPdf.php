<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Support;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\TaxReduction;
use App\Models\Company;
use App\Models\Customer;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderPhoto;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * The ÄTA as a document (rule sheet §D): what, why, price, VAT, the
 * expected ROT or RUT and what the customer pays, the effect on time and
 * payment; with the decision below it once decided. The document part
 * alone is what the SHA-256 is taken of. Names a standard contract only
 * by name, never its clauses (AO-R37). Never shows a personnummer.
 */
final class ChangeOrderPdf
{
    public static function render(ChangeOrder $changeOrder): string
    {
        // An ÄTA decided orally or performed without answer keeps the PDF rendered at the decision (review P2-2).
        if ($changeOrder->decided_document_path !== null && Storage::disk(WorkOrderPhoto::DISK)->exists($changeOrder->decided_document_path)) {
            return (string) Storage::disk(WorkOrderPhoto::DISK)->get($changeOrder->decided_document_path);
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(self::html($changeOrder, withDecision: true));
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function html(ChangeOrder $changeOrder, bool $withDecision = false): string
    {
        return CurrentCompany::run($changeOrder->company_id, function () use ($changeOrder, $withDecision): string {
            $workOrder = WorkOrder::query()->findOrFail($changeOrder->work_order_id);
            $customer = Customer::query()->findOrFail($workOrder->customer_id);
            $company = Company::query()->findOrFail($changeOrder->company_id);

            return view('work-orders::pdf.change-order', [
                'changeOrder' => $changeOrder,
                'workOrder' => $workOrder,
                'customer' => $customer,
                'company' => $company,
                'amounts' => self::amounts($changeOrder, $workOrder),
                'attempts' => $withDecision ? $changeOrder->contactAttempts()->get() : collect(),
                'withDecision' => $withDecision,
            ])->render();
        });
    }

    /**
     * Net, VAT and total, with the expected ROT or RUT on the labour
     * (30 % / 50 % of the labour including VAT, before any ceiling) and
     * what the customer pays.
     *
     * @return array{net: int, vat: int, total: int, deduction: int, to_pay: int}
     */
    public static function amounts(ChangeOrder $changeOrder, WorkOrder $workOrder): array
    {
        $rate = $workOrder->reverse_charge ? 0 : $workOrder->vat_rate;
        $totals = InvoiceCalculator::totals([['net' => $changeOrder->netOre(), 'vat_rate' => $rate]], roundToKronor: false);
        $deduction = 0;

        if ($workOrder->tax_reduction !== null && $changeOrder->tax_reduction_applies && $changeOrder->labour_ore->ore > 0) {
            $labour = InvoiceCalculator::totals([['net' => $changeOrder->labour_ore->ore, 'vat_rate' => $rate]], roundToKronor: false);
            // The rate on the day the ÄTA was sent or decided, so its document never changes afterwards (review P2-2).
            $share = TaxReduction::rate($workOrder->tax_reduction, $changeOrder->sent_at ?? $changeOrder->decided_at ?? CarbonImmutable::now()) ?? 0;
            $deduction = TaxReduction::amount($labour['net'] + $labour['vat'], $share, PHP_INT_MAX);
        }

        return ['net' => $totals['net'], 'vat' => $totals['vat'], 'total' => $totals['total'], 'deduction' => $deduction, 'to_pay' => $totals['total'] - $deduction];
    }

    public static function filename(ChangeOrder $changeOrder): string
    {
        return "ATA-{$changeOrder->number}.pdf";
    }
}
