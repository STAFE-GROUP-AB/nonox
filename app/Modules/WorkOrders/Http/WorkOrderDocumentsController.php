<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderPhoto;
use App\Modules\WorkOrders\Policies\WorkOrderPolicy;
use App\Modules\WorkOrders\Support\ChangeOrderPdf;
use App\Modules\WorkOrders\Support\SpecificationPdf;
use App\Modules\WorkOrders\WorkOrdersModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The work order's pages and documents. Another company's id answers 404
 * (the company scope), and a field worker sees only assigned work orders.
 */
class WorkOrderDocumentsController
{
    /** A field worker never sees prices (ADR-074 §8): the field screen instead. */
    public function show(int $workOrder): View|RedirectResponse
    {
        $found = $this->find($workOrder);

        if (WorkOrderPolicy::onlyAssigned($this->user(), $this->company())) {
            return redirect()->route('workOrders.field', $found->id);
        }

        return view('work-orders::show', ['workOrder' => $found]);
    }

    public function edit(int $workOrder): View
    {
        $found = $this->find($workOrder);
        Gate::authorize('manage', [WorkOrder::class, $this->company()]);

        return view('work-orders::edit', ['workOrder' => $found]);
    }

    public function field(int $workOrder): View
    {
        $found = $this->find($workOrder);
        Gate::authorize('report', $found);

        return view('work-orders::field', ['workOrder' => $found]);
    }

    /** The specified basis a consumer may ask for (KtjL 40 §, AO-R8): hours per day, rates, material and ÄTA. */
    public function specification(Request $request, int $workOrder): Response
    {
        $found = $this->find($workOrder);
        Gate::authorize('manage', [WorkOrder::class, $this->company()]);

        return response(SpecificationPdf::render($found), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.SpecificationPdf::filename($found).'"',
        ]);
    }

    public function changeOrder(int $workOrder, int $changeOrder): Response
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), WorkOrdersModule::VIEW), 404);
        $found = WorkOrder::query()->findOrFail($workOrder);
        abort_unless(Gate::allows('view', $found), 404);
        $order = ChangeOrder::query()->where('work_order_id', $found->id)->findOrFail($changeOrder);

        return response(ChangeOrderPdf::render($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.ChangeOrderPdf::filename($order).'"',
        ]);
    }

    public function photo(int $workOrder, int $photo): StreamedResponse
    {
        $found = $this->find($workOrder);
        $file = WorkOrderPhoto::query()->where('work_order_id', $found->id)->findOrFail($photo);

        return Storage::disk(WorkOrderPhoto::DISK)->response($file->path, null, ['Cache-Control' => 'private, max-age=0, no-store']);
    }

    private function find(int $id): WorkOrder
    {
        $workOrder = WorkOrder::query()->findOrFail($id);
        abort_unless(Gate::allows('view', $workOrder), 404);

        return $workOrder;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
