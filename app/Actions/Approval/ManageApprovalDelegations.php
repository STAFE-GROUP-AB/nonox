<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Domain\Approval\ApprovalContext;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Substitutes (ADR-081 gap 8): a member names another member who may decide
 * their approval steps for a date range. Everyone may set their own; one
 * with approvals:manage may set anyone's. Revoking ends it at once and keeps
 * the row, so the history still shows why a substitute could decide.
 */
class ManageApprovalDelegations
{
    public const int MAX_DAYS = 366;

    /** @param  array{user_id?: int|string, substitute_user_id?: int|string, starts_on?: string, ends_on?: string}  $input */
    public function add(User $actor, Company $company, array $input): ApprovalDelegation
    {
        $validated = Validator::make($input, [
            'user_id' => ['required', 'integer'],
            'substitute_user_id' => ['required', 'integer', 'different:user_id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [
            'substitute_user_id.different' => __('Du kan inte vara din egen ersättare.'),
            'ends_on.after_or_equal' => __('Slutdagen kan inte vara före startdagen.'),
        ], [
            'substitute_user_id' => __('ersättare'),
            'starts_on' => __('från och med'),
            'ends_on' => __('till och med'),
        ])->validate();

        $userId = (int) $validated['user_id'];
        $substituteId = (int) $validated['substitute_user_id'];
        $this->authorizeFor($actor, $company, $userId);

        $members = ApprovalContext::memberIds($company);

        if (! in_array($userId, $members, true) || ! in_array($substituteId, $members, true)) {
            throw ValidationException::withMessages(['substitute_user_id' => __('Välj en ersättare som är medlem i företaget.')]);
        }

        // The substitute must be able to approve on their own; the engine checks the exact permission again when deciding.
        $context = ApprovalContext::load($company);

        if (! $context->memberHas($substituteId, 'supplier-invoices:approve') && ! $context->memberHas($substituteId, 'payments:approve')) {
            throw ValidationException::withMessages(['substitute_user_id' => __('Välj en ersättare som har rätt att attestera leverantörsfakturor eller godkänna betalningar.')]);
        }

        $starts = CarbonImmutable::parse($validated['starts_on']);
        $ends = CarbonImmutable::parse($validated['ends_on']);

        if ($ends->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['ends_on' => __('Slutdagen har redan passerat.')]);
        }

        if ($starts->diffInDays($ends) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['ends_on' => __('En ersättare kan gälla i högst ett år åt gången.')]);
        }

        return CurrentCompany::run($company, fn (): ApprovalDelegation => DB::transaction(function () use ($actor, $company, $userId, $substituteId, $starts, $ends): ApprovalDelegation {
            $delegation = ApprovalDelegation::query()->create([
                'company_id' => $company->id,
                'user_id' => $userId,
                'substitute_user_id' => $substituteId,
                'starts_on' => $starts->toDateString(),
                'ends_on' => $ends->toDateString(),
                'created_by' => $actor->id,
            ]);

            Audit::record('approval_delegation.added', $delegation, [
                'user_id' => $userId,
                'substitute_user_id' => $substituteId,
                'starts_on' => $starts->toDateString(),
                'ends_on' => $ends->toDateString(),
            ], $company, $actor);

            return $delegation;
        }));
    }

    public function revoke(User $actor, Company $company, int $delegationId): void
    {
        CurrentCompany::run($company, function () use ($actor, $company, $delegationId): void {
            DB::transaction(function () use ($actor, $company, $delegationId): void {
                $delegation = ApprovalDelegation::query()->whereKey($delegationId)->lockForUpdate()->firstOrFail();
                $this->authorizeFor($actor, $company, $delegation->user_id);

                if ($delegation->revoked_at !== null) {
                    return;
                }

                $delegation->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();

                Audit::record('approval_delegation.revoked', $delegation, ['user_id' => $delegation->user_id, 'substitute_user_id' => $delegation->substitute_user_id], $company, $actor);
            });
        });
    }

    private function authorizeFor(User $actor, Company $company, int $userId): void
    {
        if (! $actor->belongsToTeam($company)) {
            throw new AuthorizationException;
        }

        if ($userId !== $actor->id && ! $actor->can('manage', [ApprovalPolicy::class, $company])) {
            throw new AuthorizationException(__('Bara den som får hantera attestflöden kan välja ersättare åt någon annan.'));
        }

        if ($company->isArchived()) {
            throw new AuthorizationException;
        }
    }
}
