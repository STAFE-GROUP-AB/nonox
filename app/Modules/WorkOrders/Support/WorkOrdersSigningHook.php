<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Support;

use App\Domain\Signing\SigningHook;
use App\Models\Company;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Policies\WorkOrderPolicy;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;

/**
 * Arbetsorder's steps in the signing follow-up (ADR-074 §4): an ÄTA the
 * customer declines at the link becomes DECLINED and is kept as it was.
 * Signing itself is handled by ChangeOrder::markSigned. The work order's
 * creator is told, besides the sender.
 */
final class WorkOrdersSigningHook implements SigningHook
{
    public function signed(Company $company, SigningRequest $request, ?User $actor, ?string $orderId, ?string $invoiceId): array
    {
        return [];
    }

    public function declined(Company $company, SigningRequest $request): void
    {
        if ($request->document_type !== ChangeOrder::SIGNING_TYPE || ! app(ModuleManager::class)->isEnabled(WorkOrdersModule::KEY, $company)) {
            return;
        }

        $changeOrder = ChangeOrder::query()->whereKey((int) $request->signable_id)->lockForUpdate()->first();

        if (! $changeOrder instanceof ChangeOrder || $changeOrder->status !== ChangeOrderStatus::Sent) {
            return;
        }

        $changeOrder->forceFill([
            'status' => ChangeOrderStatus::Declined,
            'decided_at' => $request->declined_at ?? CarbonImmutable::now(),
            'approval_method' => 'LINK',
        ])->save();

        Audit::record('work_orders.change_order_decided', $changeOrder, ['work_order_id' => $changeOrder->work_order_id, 'status' => ChangeOrderStatus::Declined->value, 'method' => 'LINK', 'signing_request_id' => $request->id], $company);
    }

    /** A field worker never gets the signed copy with its prices (ADR-074 §8, review P2-1). */
    public function mayReceiveCopy(Company $company, User $user, SigningRequest $request): bool
    {
        if (WorkOrderPolicy::onlyAssigned($user, $company)) {
            return false;
        }

        // An ÄTA's files and copy only for those who may see work orders (signed-file route, ADR-074 review).
        return $request->document_type !== ChangeOrder::SIGNING_TYPE || $user->hasTeamPermission($company, WorkOrdersModule::VIEW);
    }

    public function recipients(Company $company, SigningRequest $request): array
    {
        if ($request->document_type !== ChangeOrder::SIGNING_TYPE) {
            return [];
        }

        $workOrderId = ChangeOrder::query()->whereKey((int) $request->signable_id)->value('work_order_id');
        $creator = $workOrderId === null ? null : WorkOrder::query()->whereKey($workOrderId)->value('created_by');

        return $creator === null ? [] : [(int) $creator];
    }
}
