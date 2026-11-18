<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalDimension;
use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Names (or clears) the owner of a cost centre or project, who can then be
 * the approver of a step "Ägaren av kostnadsstället eller projektet"
 * (ADR-081 gap 8). The owner must be a member of the company.
 */
class SetDimensionOwner
{
    public function __invoke(User $user, Company $company, ApprovalDimension $dimension, int $id, ?int $ownerId): void
    {
        Gate::forUser($user)->authorize('manage', [ApprovalPolicy::class, $company]);

        if ($ownerId !== null && ! in_array($ownerId, ApprovalContext::memberIds($company), true)) {
            throw ValidationException::withMessages(['owner' => __('Välj en ägare som är medlem i företaget.')]);
        }

        // An owner approves supplier invoices booked on the object, so needs the right to attest them.
        if ($ownerId !== null && ! ApprovalContext::load($company)->memberHas($ownerId, 'supplier-invoices:approve')) {
            throw ValidationException::withMessages(['owner' => __('Välj en ägare som har rätt att attestera leverantörsfakturor.')]);
        }

        CurrentCompany::run($company, function () use ($user, $company, $dimension, $id, $ownerId): void {
            DB::transaction(function () use ($user, $company, $dimension, $id, $ownerId): void {
                $object = $dimension === ApprovalDimension::Project
                    ? Project::query()->whereKey($id)->lockForUpdate()->firstOrFail()
                    : CostCenter::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                $object->forceFill(['owner_user_id' => $ownerId])->save();

                Audit::record('dimension.owner_changed', $object, ['dimension' => $dimension->value, 'owner_user_id' => $ownerId], $company, $user);
            });
        });
    }
}
