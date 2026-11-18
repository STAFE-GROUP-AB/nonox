<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\SubjectFacts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionApprovals;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * What the requester (or a buyer) does with a purchase requisition besides
 * writing it (ADR-081 E9): send it to approval, take it back from approval to
 * change it, or cancel it. Approving, rejecting and sending a rejected one
 * again are the approval actions (ApprovePurchaseRequisition and its siblings).
 */
class PurchaseRequisitionLifecycle
{
    /** Sends a draft to approval. The company's approval flow, or the default one, takes it from here. */
    public function submit(User $user, Company $company, PurchaseRequisition $requisition): PurchaseRequisition
    {
        return $this->locked($user, $company, $requisition, function (PurchaseRequisition $requisition) use ($user, $company): PurchaseRequisition {
            if ($requisition->status !== PurchaseRequisitionStatus::Draft) {
                throw ValidationException::withMessages(['requisition' => __('Bara ett utkast kan skickas till attest.')]);
            }

            if (! $requisition->rows()->exists()) {
                throw ValidationException::withMessages(['requisition' => __('Lägg till minst en rad innan du skickar anmodan.')]);
            }

            $requisition->forceFill(['status' => PurchaseRequisitionStatus::Submitted, 'submitted_at' => now(), 'prepared_by' => $requisition->prepared_by ?? $user->id])->save();
            Audit::record('inventory.purchase_requisition_submitted', $requisition, ['number' => $requisition->number, 'round' => $requisition->approval_round], $company, $user);

            return $requisition;
        });
    }

    /** Takes a requisition back from approval, as a draft. The approver's page is then stale, so nothing is approved unseen. */
    public function takeBack(User $user, Company $company, PurchaseRequisition $requisition): PurchaseRequisition
    {
        return $this->locked($user, $company, $requisition, function (PurchaseRequisition $requisition) use ($user, $company): PurchaseRequisition {
            if ($requisition->status !== PurchaseRequisitionStatus::Submitted) {
                throw ValidationException::withMessages(['requisition' => __('Bara en anmodan som väntar på attest kan tas tillbaka.')]);
            }

            $requisition->forceFill(['status' => PurchaseRequisitionStatus::Draft, 'submitted_at' => null])->save();
            PurchaseRequisitionApprovals::startNewRound($requisition, $user);
            Audit::record('inventory.purchase_requisition_taken_back', $requisition, ['number' => $requisition->number, 'round' => $requisition->approval_round], $company, $user);

            return $requisition;
        });
    }

    public function cancel(User $user, Company $company, PurchaseRequisition $requisition): PurchaseRequisition
    {
        return $this->locked($user, $company, $requisition, function (PurchaseRequisition $requisition) use ($user, $company): PurchaseRequisition {
            if (! $requisition->status->isOpen()) {
                throw ValidationException::withMessages(['requisition' => __('Inköpsanmodan är redan omvandlad eller makulerad.')]);
            }

            if ($requisition->rows()->whereNotNull('purchase_order_row_id')->exists()) {
                throw ValidationException::withMessages(['requisition' => __('En del av anmodan är redan beställd. Ta bort raderna från inköpsordern först.')]);
            }

            // An approval still open ends with the requisition.
            if ($requisition->status === PurchaseRequisitionStatus::Submitted) {
                $requisition->loadMissing('rows');
                app(ApprovalEngine::class)->withdraw(SubjectFacts::forPurchaseRequisition($requisition), $user);
            }

            $requisition->forceFill(['status' => PurchaseRequisitionStatus::Cancelled, 'cancelled_at' => now()])->save();
            Audit::record('inventory.purchase_requisition_cancelled', $requisition, ['number' => $requisition->number], $company, $user);

            return $requisition;
        });
    }

    /**
     * Runs $action on the requisition locked inside the company, after checking
     * that the user may handle it (also on the locked row).
     *
     * @param  callable(PurchaseRequisition): PurchaseRequisition  $action
     */
    private function locked(User $user, Company $company, PurchaseRequisition $requisition, callable $action): PurchaseRequisition
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);
        Gate::forUser($user)->authorize('handle', [$requisition, $company]);

        return CurrentCompany::run($company, fn (): PurchaseRequisition => DB::transaction(function () use ($requisition, $user, $company, $action): PurchaseRequisition {
            $locked = PurchaseRequisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if (! Gate::forUser($user)->allows('handle', [$locked, $company])) {
                throw new AuthorizationException;
            }

            return $action($locked);
        }));
    }
}
