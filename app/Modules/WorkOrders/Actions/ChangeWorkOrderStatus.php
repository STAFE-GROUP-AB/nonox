<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Support\Uninvoiced;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a work order along DRAFT → PLANNED → IN_PROGRESS → DONE → CLOSED
 * (ADR-074 §2). Closing needs everything billable invoiced; closing or
 * cancelling removes the access notes (AO-R39).
 */
class ChangeWorkOrderStatus
{
    use GuardsWorkOrders;

    public function __invoke(User $user, Company $company, WorkOrder $workOrder, WorkOrderStatus $to): WorkOrder
    {
        $this->guard($user, $company);

        return DB::transaction(fn (): WorkOrder => CurrentCompany::run($company, function () use ($user, $company, $workOrder, $to): WorkOrder {
            $workOrder = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $from = $workOrder->status;

            if (! in_array($to, $from->next(), true)) {
                throw ValidationException::withMessages(['status' => __('Arbetsordern kan inte gå från :from till :to.', ['from' => $from->label(), 'to' => $to->label()])]);
            }

            if ($to === WorkOrderStatus::Closed && Uninvoiced::count($workOrder) > 0) {
                throw ValidationException::withMessages(['status' => __('Det finns tid eller material som inte är fakturerat. Skapa fakturaunderlag, eller ändra posterna till ej debiterbara genom att backa dem, innan arbetsordern avslutas.')]);
            }

            if ($to === WorkOrderStatus::Cancelled && ($workOrder->timeEntries()->exists() || $workOrder->materialEntries()->exists())) {
                throw ValidationException::withMessages(['status' => __('Det finns redan tid eller material på arbetsordern. Avsluta den i stället för att makulera den.')]);
            }

            $workOrder->forceFill([
                'status' => $to,
                'done_at' => $to === WorkOrderStatus::Done ? CarbonImmutable::now() : ($to === WorkOrderStatus::InProgress ? null : $workOrder->done_at),
                'closed_at' => $to === WorkOrderStatus::Closed ? CarbonImmutable::now() : $workOrder->closed_at,
                // Door codes and key handover are not needed after the job (AO-R39, L16).
                'access_notes' => $to->isOpen() ? $workOrder->access_notes : null,
            ])->save();

            Audit::record('work_orders.status_changed', $workOrder, ['from' => $from->value, 'to' => $to->value], $company, $user);

            return $workOrder;
        }));
    }
}
