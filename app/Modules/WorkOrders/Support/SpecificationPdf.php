<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Support\Tenancy\CurrentCompany;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The specified basis for a work order (KtjL 40 §, AO-R8): hours per day
 * and kind of work with the rate, material per row, and each ÄTA by
 * number, so the customer can judge the kind and extent of the work and
 * how the price was calculated. Live billable entries only. No personnummer.
 */
final class SpecificationPdf
{
    public static function render(WorkOrder $workOrder): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(self::html($workOrder));
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function html(WorkOrder $workOrder): string
    {
        return CurrentCompany::run($workOrder->company_id, fn (): string => view('work-orders::pdf.specification', [
            'workOrder' => $workOrder,
            'company' => Company::query()->findOrFail($workOrder->company_id),
            'customer' => Customer::query()->findOrFail($workOrder->customer_id),
            'time' => Uninvoiced::live(TimeEntry::query()->where('work_order_id', $workOrder->id))->where('billable', true)->with('labourKind')->orderBy('work_date')->get(),
            'material' => Uninvoiced::live(MaterialEntry::query()->where('work_order_id', $workOrder->id))->where('billable', true)->orderBy('work_date')->get(),
            'changeOrders' => ChangeOrder::query()->where('work_order_id', $workOrder->id)->get()->keyBy('id'),
        ])->render());
    }

    public static function filename(WorkOrder $workOrder): string
    {
        return "Arbetsorder-{$workOrder->number}-underlag.pdf";
    }
}
