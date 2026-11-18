<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Models\ApprovalDecision;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Modules\Connect\Support\ConnectAccess;
use App\Support\Audit\Audit;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The approval engine (ADR-081 gap 8, E2). A company's own policies decide
 * how a subject is approved: steps in order, each with a condition, its
 * approvers, how many must approve and whether the preparer is kept out.
 *
 * A subject that no policy of the company's own applies to follows the
 * default policy, which is today's behaviour exactly: one step, the
 * subject's own permission (supplier-invoices:approve, payments:approve),
 * one approval, and the preparer kept out while the company requires
 * separate approval. The subject's actions run that path unchanged and
 * write no request rows (DecisionOutcome::Default).
 *
 * Segregation of duties: while the company requires separate approval
 * (`separate_supplier_approval`, AGENTS.md §4), the preparer is kept out of
 * every step whatever the policy says. A policy can add the rule, never
 * remove it.
 *
 * The subject's effect (an invoice's status Approved, a payment file's
 * approval) is applied by the subject's existing action after the last
 * approval of the last step, so its triggers and audit entries stay as they
 * are.
 */
final class ApprovalEngine
{
    public const int REASON_MAX = 500;

    /**
     * Whether the subject leaves the default path: it has a request already,
     * or one of the company's active policies has a step that applies to it.
     * One query when the company has no policy for the type and the subject
     * no request; the facts are only read when there is a policy to test.
     *
     * @param  Closure(): SubjectFacts  $facts
     */
    public function hasCustomFlow(int $companyId, ApprovalSubjectType $type, string $subjectId, Closure $facts): bool
    {
        $policies = ApprovalPolicy::query()
            ->where('company_id', $companyId)
            ->where('subject_type', $type->value)
            ->where('is_active', true)
            ->with('steps')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if (ApprovalRequest::query()->where('company_id', $companyId)->where('subject_type', $type->value)->where('subject_id', $subjectId)->exists()) {
            return true;
        }

        if ($policies->isEmpty()) {
            return false;
        }

        $subject = $facts();

        return $policies->contains(fn (ApprovalPolicy $policy): bool => PolicySnapshot::of($policy, $subject) instanceof PolicySnapshot);
    }

    /** The first active policy (by priority) with a step that applies, or null for the default policy. */
    public function resolve(ApprovalContext $context, SubjectFacts $facts): ?PolicySnapshot
    {
        foreach ($context->policiesFor($facts->type) as $policy) {
            $snapshot = PolicySnapshot::of($policy, $facts);

            if ($snapshot instanceof PolicySnapshot) {
                return $snapshot;
            }
        }

        return null;
    }

    /** Today's behaviour as a snapshot, for a subject sent back to approval after a rejection when no policy applies any more. */
    public function defaultSnapshot(ApprovalSubjectType $type): PolicySnapshot
    {
        return new PolicySnapshot(null, __('Standard'), 1, [
            new SnapshotStep(ApproverKind::Permission, $type->basePermission(), [], null, 1, false, new StepConditions),
        ]);
    }

    /**
     * Every request on each subject, oldest first, with their decisions: two
     * queries for any number of subjects.
     *
     * @param  list<string>  $subjectIds
     * @return Collection<int|string, EloquentCollection<int, ApprovalRequest>>
     */
    public function histories(int $companyId, ApprovalSubjectType $type, array $subjectIds): Collection
    {
        if ($subjectIds === []) {
            return collect();
        }

        return ApprovalRequest::query()
            ->where('company_id', $companyId)
            ->where('subject_type', $type->value)
            ->whereIn('subject_id', $subjectIds)
            ->with(['decisions.user:id,name', 'decisions.onBehalfOf:id,name'])
            ->orderBy('id')
            ->get()
            ->groupBy('subject_id')
            ->toBase();
    }

    /** @param  ?Collection<int, ApprovalRequest>  $history  as from histories(); read when not given */
    public function state(ApprovalContext $context, User $actor, SubjectFacts $facts, ?Collection $history = null): ApprovalState
    {
        $history ??= $this->histories($facts->companyId, $facts->type, [$facts->subjectId])->get($facts->subjectId, collect());
        $history = $history->values();

        $pending = $history->first(fn (ApprovalRequest $request): bool => $request->isPending());

        if ($pending instanceof ApprovalRequest) {
            $snapshot = $pending->snapshot();

            return new ApprovalState(true, $pending, $snapshot, false, $this->eligibility($context, $actor, $facts, $snapshot, $pending->current_step, $pending->decisions), $history);
        }

        $latest = $history->last(fn (ApprovalRequest $request): bool => $request->status !== ApprovalRequestStatus::Withdrawn);

        if ($latest instanceof ApprovalRequest && $latest->status === ApprovalRequestStatus::Rejected) {
            return new ApprovalState(true, null, $latest->snapshot(), true, Eligibility::deny(Eligibility::CLOSED), $history);
        }

        if ($latest instanceof ApprovalRequest) {
            return new ApprovalState(true, null, $latest->snapshot(), false, Eligibility::deny(Eligibility::CLOSED), $history);
        }

        $snapshot = $this->resolve($context, $facts);

        if (! $snapshot instanceof PolicySnapshot) {
            return ApprovalState::default();
        }

        return new ApprovalState(true, null, $snapshot, false, $this->eligibility($context, $actor, $facts, $snapshot, 1, collect()), $history);
    }

    /**
     * Whether the actor may decide step $number, and for whom.
     *
     * @param  Collection<int, ApprovalDecision>  $decisions  the request's decisions so far
     */
    public function eligibility(ApprovalContext $context, User $actor, SubjectFacts $facts, PolicySnapshot $snapshot, int $number, Collection $decisions): Eligibility
    {
        $company = $context->company;

        // Over the API only a credential allowed to approve the subject may decide (ADR-032 scopes).
        if (ConnectAccess::restricts($company, $facts->type->basePermission()) || $company->isArchived() || ! $actor->belongsToTeam($company)) {
            return Eligibility::deny(Eligibility::NOT_APPROVER);
        }

        $step = $snapshot->step($number);
        $atStep = $decisions->filter(fn (ApprovalDecision $decision): bool => $decision->step === $number);
        $decided = $atStep->map(fn (ApprovalDecision $decision): int => $decision->principalId())->all();

        if ($atStep->contains(fn (ApprovalDecision $decision): bool => $decision->user_id === $actor->id) || in_array($actor->id, $decided, true)) {
            return Eligibility::deny(Eligibility::ALREADY_DECIDED);
        }

        $excludePreparer = $step->excludePreparer || $context->separateApproval();

        if ($excludePreparer && in_array($actor->id, $facts->preparerIds, true)) {
            return $this->isPrincipal($context, $actor, $actor->id, $step, $facts)
                ? Eligibility::deny(Eligibility::PREPARER)
                : Eligibility::deny(Eligibility::NOT_APPROVER);
        }

        if ($this->isPrincipal($context, $actor, $actor->id, $step, $facts)) {
            return Eligibility::allow();
        }

        // A substitute stands in only for steps that name people, and must hold the subject's own approval permission themselves.
        if (! $this->delegable($context, $step, $facts) || ! $actor->hasTeamPermission($company, $facts->type->basePermission())) {
            return Eligibility::deny(Eligibility::NOT_APPROVER);
        }

        foreach ($context->delegationsTo($actor->id) as $delegation) {
            $principal = $delegation->user_id;

            if (in_array($principal, $decided, true) || ($excludePreparer && in_array($principal, $facts->preparerIds, true))) {
                continue;
            }

            if ($this->isPrincipal($context, $actor, $principal, $step, $facts)) {
                return Eligibility::allow($principal);
            }
        }

        return Eligibility::deny(Eligibility::NOT_APPROVER);
    }

    /**
     * Records a decision on the subject's request, opening one from the
     * company's policy when none is open. Call it inside a transaction with
     * the subject locked. Throws AuthorizationException when the actor is no
     * approver of the step, ValidationException for the other refusals.
     */
    public function decide(ApprovalContext $context, User $actor, SubjectFacts $facts, ApprovalDecisionKind $kind, ?string $reason = null): DecisionOutcome
    {
        $reason = $this->reason($reason);
        $request = $this->lockPending($facts);

        if (! $request instanceof ApprovalRequest) {
            $latest = ApprovalRequest::query()
                ->where('company_id', $facts->companyId)
                ->where('subject_type', $facts->type->value)
                ->where('subject_id', $facts->subjectId)
                ->where('status', '!=', ApprovalRequestStatus::Withdrawn->value)
                ->orderByDesc('id')
                ->first();

            if ($latest?->status === ApprovalRequestStatus::Rejected) {
                throw ValidationException::withMessages(['approve' => __('Underlaget avvisades i attesten. Den som förberedde det kan skicka det till attest igen.')]);
            }

            $snapshot = $this->resolve($context, $facts);

            if (! $snapshot instanceof PolicySnapshot) {
                if ($kind === ApprovalDecisionKind::Reject) {
                    throw ValidationException::withMessages(['approve' => __('Underlaget följer standardattesten, som inte har något steg att avvisa i.')]);
                }

                return DecisionOutcome::Default;
            }

            $request = $this->open($facts, $snapshot, $actor);
        }

        $snapshot = $request->snapshot();
        $decisions = $request->decisions()->get();
        $eligibility = $this->eligibility($context, $actor, $facts, $snapshot, $request->current_step, $decisions);

        // Owners and administrators may always reject, so a request whose approver has left never gets stuck.
        if (! $eligibility->allowed && $kind === ApprovalDecisionKind::Reject && $this->canAlwaysReject($context, $actor, $facts)) {
            $eligibility = Eligibility::allow();
        }

        if (! $eligibility->allowed) {
            if ($eligibility->reason === Eligibility::NOT_APPROVER) {
                throw new AuthorizationException((string) $eligibility->message());
            }

            throw ValidationException::withMessages(['approve' => (string) $eligibility->message()]);
        }

        $decision = ApprovalDecision::query()->create([
            'company_id' => $facts->companyId,
            'approval_request_id' => $request->id,
            'step' => $request->current_step,
            'user_id' => $actor->id,
            'on_behalf_of' => $eligibility->onBehalfOf,
            'decision' => $kind->value,
            'reason' => $reason,
            'decided_at' => now(),
        ]);

        $outcome = $this->advance($request, $snapshot, $decisions->push($decision), $kind);

        Audit::record('approval.decided', $request, [
            'subject_type' => $facts->type->value,
            'subject_id' => $facts->subjectId,
            'step' => $decision->step,
            'decision' => $kind->value,
            'on_behalf_of' => $eligibility->onBehalfOf,
            'outcome' => $outcome->value,
        ], $facts->companyId, $actor);

        return $outcome;
    }

    /**
     * Sends a rejected subject to approval again, with the company's policy
     * as it is now (or the default one when none applies any more). Call it
     * inside a transaction with the subject locked.
     */
    public function resubmit(ApprovalContext $context, User $actor, SubjectFacts $facts): ApprovalRequest
    {
        if ($this->lockPending($facts) instanceof ApprovalRequest) {
            throw ValidationException::withMessages(['approve' => __('Underlaget väntar redan på attest.')]);
        }

        $latest = ApprovalRequest::query()
            ->where('company_id', $facts->companyId)
            ->where('subject_type', $facts->type->value)
            ->where('subject_id', $facts->subjectId)
            ->where('status', '!=', ApprovalRequestStatus::Withdrawn->value)
            ->orderByDesc('id')
            ->first();

        if ($latest?->status !== ApprovalRequestStatus::Rejected) {
            throw ValidationException::withMessages(['approve' => __('Bara ett underlag som har avvisats kan skickas till attest igen.')]);
        }

        return $this->open($facts, $this->resolve($context, $facts) ?? $this->defaultSnapshot($facts->type), $actor);
    }

    /** Ends an open request because its subject is gone (a cancelled payment file). */
    public function withdraw(SubjectFacts $facts, User $actor): void
    {
        $request = $this->lockPending($facts);

        if (! $request instanceof ApprovalRequest) {
            return;
        }

        $request->forceFill(['status' => ApprovalRequestStatus::Withdrawn, 'completed_at' => now()])->save();

        Audit::record('approval.withdrawn', $request, ['subject_type' => $facts->type->value, 'subject_id' => $facts->subjectId], $facts->companyId, $actor);
    }

    /**
     * Whether the actor may reject whatever the current step says: an owner
     * or administrator (approvals:manage). Rejecting approves nothing; the
     * subject only goes back to its preparer.
     */
    public function canAlwaysReject(ApprovalContext $context, User $actor, SubjectFacts $facts): bool
    {
        $company = $context->company;

        return ! ConnectAccess::restricts($company, $facts->type->basePermission())
            && $actor->hasTeamPermission($company, 'approvals:manage');
    }

    private function open(SubjectFacts $facts, PolicySnapshot $snapshot, User $actor): ApprovalRequest
    {
        $request = ApprovalRequest::query()->create([
            'company_id' => $facts->companyId,
            'subject_type' => $facts->type->value,
            'subject_id' => $facts->subjectId,
            'approval_policy_id' => $snapshot->policyId,
            'policy_snapshot' => $snapshot->toArray(),
            'prepared_by' => $facts->preparedBy(),
        ]);

        Audit::record('approval.requested', $request, [
            'subject_type' => $facts->type->value,
            'subject_id' => $facts->subjectId,
            'policy_id' => $snapshot->policyId,
            'policy_version' => $snapshot->version,
            'steps' => $snapshot->count(),
        ], $facts->companyId, $actor);

        return $request;
    }

    private function lockPending(SubjectFacts $facts): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('company_id', $facts->companyId)
            ->where('subject_type', $facts->type->value)
            ->where('subject_id', $facts->subjectId)
            ->where('status', ApprovalRequestStatus::Pending->value)
            ->lockForUpdate()
            ->first();
    }

    /** @param  Collection<int, ApprovalDecision>  $decisions */
    private function advance(ApprovalRequest $request, PolicySnapshot $snapshot, Collection $decisions, ApprovalDecisionKind $kind): DecisionOutcome
    {
        if ($kind === ApprovalDecisionKind::Reject) {
            $request->forceFill(['status' => ApprovalRequestStatus::Rejected, 'completed_at' => now()])->save();

            return DecisionOutcome::Rejected;
        }

        $step = $snapshot->step($request->current_step);
        $approvals = $decisions
            ->filter(fn (ApprovalDecision $decision): bool => $decision->step === $request->current_step && $decision->decision === ApprovalDecisionKind::Approve)
            ->map(fn (ApprovalDecision $decision): int => $decision->principalId())
            ->unique()
            ->count();

        if ($approvals < $step->requiredCount) {
            return DecisionOutcome::Recorded;
        }

        if ($request->current_step >= $snapshot->count()) {
            $request->forceFill(['status' => ApprovalRequestStatus::Approved, 'completed_at' => now()])->save();

            return DecisionOutcome::Completed;
        }

        $request->forceFill(['current_step' => $request->current_step + 1])->save();

        return DecisionOutcome::Advanced;
    }

    /**
     * Whether $principalId is an approver of the step. $actor is the person
     * deciding: their own permissions go through User::hasTeamPermission
     * (API scopes, archived companies, modules); another person's through the
     * membership read with the context.
     */
    private function isPrincipal(ApprovalContext $context, User $actor, int $principalId, SnapshotStep $step, SubjectFacts $facts): bool
    {
        $self = $principalId === $actor->id;
        $company = $context->company;

        if (! $context->isMember($principalId)) {
            return false;
        }

        $has = fn (string $permission): bool => $self
            ? $actor->hasTeamPermission($company, $permission)
            : $context->memberHas($principalId, $permission);

        // A named person or owner must also hold the subject's own approval permission: a policy narrows who approves, never widens it.
        $approves = $has($facts->type->basePermission());

        return match ($step->kind) {
            ApproverKind::Permission => $self && $has((string) $step->permission),
            ApproverKind::Users => in_array($principalId, $step->userIds, true) && $approves,
            ApproverKind::DimensionOwner => $this->ownerOrFallback($context, $principalId, $self, $step, $facts, $has, $approves),
        };
    }

    /**
     * The owner of one of the subject's cost centres or projects. With no
     * owner who can decide (no dimension on the subject, no owner set, or the
     * owner has left), the step falls back to the subject's own permission,
     * so a subject is never stuck.
     *
     * @param  Closure(string): bool  $has
     */
    private function ownerOrFallback(ApprovalContext $context, int $principalId, bool $self, SnapshotStep $step, SubjectFacts $facts, Closure $has, bool $approves): bool
    {
        $owners = $context->dimensionOwners($step->dimension, $facts);

        if ($owners === []) {
            return $self && $has($facts->type->basePermission());
        }

        return $approves && in_array($principalId, $owners, true);
    }

    /** A substitute can stand in only where the step names people (not on a permission or the permission fallback). */
    private function delegable(ApprovalContext $context, SnapshotStep $step, SubjectFacts $facts): bool
    {
        if (! $step->kind->allowsDelegation()) {
            return false;
        }

        return $step->kind !== ApproverKind::DimensionOwner || $context->dimensionOwners($step->dimension, $facts) !== [];
    }

    private function reason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        if ($reason === null || $reason === '') {
            return null;
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => __('Skälet får vara högst :max tecken.', ['max' => self::REASON_MAX])]);
        }

        return $reason;
    }
}
