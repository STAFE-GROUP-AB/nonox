<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes a company's own approval policy. Requests already running keep the
 * copy they started with and finish under it; new subjects follow the next
 * policy that applies, or the default policy.
 */
class DeleteApprovalPolicy
{
    public function __invoke(User $user, Company $company, int $policyId): void
    {
        Gate::forUser($user)->authorize('manage', [ApprovalPolicy::class, $company]);

        CurrentCompany::run($company, function () use ($user, $company, $policyId): void {
            DB::transaction(function () use ($user, $company, $policyId): void {
                $policy = ApprovalPolicy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();

                Audit::record('approval_policy.deleted', $policy, ['subject_type' => $policy->subject_type->value, 'version' => $policy->version], $company, $user);

                $policy->delete();
            });
        });
    }
}
