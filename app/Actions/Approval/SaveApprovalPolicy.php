<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalDimension;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\ApproverKind;
use App\Domain\Approval\StepConditions;
use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or changes a company's own approval policy (ADR-081 gap 8). Each
 * save raises the version and replaces the steps; requests already running
 * keep the copy they started with. Conditions are stored in the validated
 * StepConditions form, and only conditions and approver kinds that mean
 * something for the subject type are accepted.
 */
class SaveApprovalPolicy
{
    public const int MAX_STEPS = 10;

    /**
     * @param  array{
     *     subject_type?: string,
     *     name?: string,
     *     priority?: int|string,
     *     is_active?: bool,
     *     steps?: array<int, array<string, mixed>>
     * }  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?int $policyId = null): ApprovalPolicy
    {
        Gate::forUser($user)->authorize('manage', [ApprovalPolicy::class, $company]);

        $validated = Validator::make($input, [
            'subject_type' => ['required', Rule::in(array_map(fn (ApprovalSubjectType $type): string => $type->value, ApprovalSubjectType::wired()))],
            'name' => ['required', 'string', 'max:120'],
            'priority' => ['required', 'integer', 'min:1', 'max:9999'],
            'is_active' => ['boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:'.self::MAX_STEPS],
            'steps.*.approver_kind' => ['required', Rule::enum(ApproverKind::class)],
            'steps.*.approver_permission' => ['nullable', 'string'],
            'steps.*.approver_user_ids' => ['nullable', 'array'],
            'steps.*.approver_user_ids.*' => ['integer'],
            'steps.*.approver_dimension' => ['nullable', Rule::enum(ApprovalDimension::class)],
            'steps.*.required_count' => ['required', 'integer', 'min:1', 'max:20'],
            'steps.*.exclude_preparer' => ['boolean'],
        ], [], [
            'subject_type' => __('vad flödet gäller'),
            'name' => __('namn'),
            'priority' => __('ordning'),
            'steps' => __('steg'),
            'steps.*.required_count' => __('antal som ska godkänna'),
        ])->validate();

        $type = ApprovalSubjectType::from($validated['subject_type']);

        if ($type === ApprovalSubjectType::PurchaseOrder) {
            app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);
        }
        $steps = [];

        foreach ($input['steps'] ?? [] as $index => $step) {
            $steps[] = $this->step($company, $type, $step, $index);
        }

        return CurrentCompany::run($company, fn (): ApprovalPolicy => DB::transaction(function () use ($user, $company, $type, $validated, $steps, $policyId): ApprovalPolicy {
            $policy = $policyId === null
                ? new ApprovalPolicy(['company_id' => $company->id, 'created_by' => $user->id])
                : ApprovalPolicy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();

            $policy->fill([
                'subject_type' => $type,
                'name' => trim($validated['name']),
                'priority' => (int) $validated['priority'],
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);
            $policy->forceFill([
                'version' => $policy->exists ? $policy->version + 1 : 1,
                'updated_by' => $user->id,
            ])->save();

            $policy->steps()->delete();

            foreach ($steps as $position => $step) {
                $policy->steps()->create([...$step, 'company_id' => $company->id, 'position' => $position + 1]);
            }

            Audit::record('approval_policy.saved', $policy, [
                'subject_type' => $type->value,
                'version' => $policy->version,
                'steps' => count($steps),
                'is_active' => $policy->is_active,
            ], $company, $user);

            return $policy->load('steps');
        }));
    }

    /**
     * One step in its stored form, or a validation error naming the step.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function step(Company $company, ApprovalSubjectType $type, array $step, int $index): array
    {
        $field = fn (string $name): string => "steps.{$index}.{$name}";
        $fail = fn (string $name, string $message): never => throw ValidationException::withMessages([$field($name) => $message]);
        $kind = ApproverKind::from((string) $step['approver_kind']);

        if (! in_array($kind, $type->approverKinds(), true)) {
            $fail('approver_kind', __('Det sättet att välja attestant finns inte för :type.', ['type' => mb_strtolower($type->label())]));
        }

        $permission = null;
        $users = null;
        $dimension = null;

        if ($kind === ApproverKind::Permission) {
            $permission = (string) ($step['approver_permission'] ?? '');

            if (! in_array($permission, $type->stepPermissions(), true)) {
                $fail('approver_permission', __('Välj vilken behörighet som krävs.'));
            }
        }

        if ($kind === ApproverKind::Users) {
            $users = array_values(array_unique(array_map('intval', (array) ($step['approver_user_ids'] ?? []))));
            $members = ApprovalContext::memberIds($company);

            if ($users === [] || array_diff($users, $members) !== []) {
                $fail('approver_user_ids', __('Välj minst en person som är medlem i företaget.'));
            }

            $context = ApprovalContext::load($company);

            foreach ($users as $userId) {
                if (! $context->memberHas($userId, $type->basePermission())) {
                    $fail('approver_user_ids', __('Välj bara personer som har rätt att attestera :type.', ['type' => mb_strtolower($type->label())]));
                }
            }

            if ((int) $step['required_count'] > count($users)) {
                $fail('required_count', __('Fler ska godkänna än det finns utvalda personer.'));
            }
        }

        if ($kind === ApproverKind::DimensionOwner) {
            $dimension = ApprovalDimension::tryFrom((string) ($step['approver_dimension'] ?? ''))
                ?? $fail('approver_dimension', __('Välj kostnadsställe eller projekt.'));

            if ((int) $step['required_count'] !== 1) {
                $fail('required_count', __('Ett steg för ägaren kan bara kräva ett godkännande.'));
            }
        }

        $amountError = __('Skriv beloppet som 50 000 eller 50 000,00.');
        $accountError = __('Skriv ett kontonummer mellan 1000 och 9999.');

        try {
            $conditions = new StepConditions(
                minOre: $this->amount($step['min_amount'] ?? null, fn () => $fail('min_amount', $amountError)),
                maxOre: $this->amount($step['max_amount'] ?? null, fn () => $fail('max_amount', $amountError)),
                costCenterIds: $this->id(CostCenter::class, $company, $step['cost_center_id'] ?? null, fn () => $fail('cost_center_id', __('Kostnadsstället finns inte.'))),
                projectIds: $this->id(Project::class, $company, $step['project_id'] ?? null, fn () => $fail('project_id', __('Projektet finns inte.'))),
                supplierIds: $this->id(Supplier::class, $company, $step['supplier_id'] ?? null, fn () => $fail('supplier_id', __('Leverantören finns inte.'))),
                accountFrom: $this->account($step['account_from'] ?? null, fn () => $fail('account_from', $accountError)),
                accountTo: $this->account($step['account_to'] ?? null, fn () => $fail('account_to', $accountError)),
            );
        } catch (InvalidArgumentException) {
            $fail('max_amount', __('Från-värdet måste vara lägre än till-värdet.'));
        }

        if (array_diff($conditions->usedKeys(), $type->conditionKeys()) !== []) {
            $type === ApprovalSubjectType::PaymentBatch
                ? $fail('cost_center_id', __('För betalfiler kan villkoret bara vara ett belopp.'))
                : $fail('supplier_id', __('En leverantör kan inte vara villkor för :type.', ['type' => mb_strtolower($type->label())]));
        }

        return [
            'conditions' => $conditions->toArray(),
            'approver_kind' => $kind,
            'approver_permission' => $permission,
            'approver_user_ids' => $users,
            'approver_dimension' => $dimension,
            'required_count' => (int) $step['required_count'],
            'exclude_preparer' => (bool) ($step['exclude_preparer'] ?? true),
        ];
    }

    /**
     * Kronor to öre; empty is no limit.
     *
     * @param  Closure(): never  $fail
     */
    private function amount(mixed $value, Closure $fail): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            $ore = Money::parse((string) $value)->ore;
        } catch (InvalidArgumentException) {
            $fail();
        }

        return $ore >= 0 ? $ore : $fail();
    }

    /**
     * The id as a one-element list when it is the company's; empty is none.
     *
     * @param  class-string<CostCenter|Project|Supplier>  $model
     * @param  Closure(): never  $fail
     * @return list<int>
     */
    private function id(string $model, Company $company, mixed $value, Closure $fail): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $id = (int) $value;

        return $model::query()->where('company_id', $company->id)->whereKey($id)->exists() ? [$id] : $fail();
    }

    /** @param  Closure(): never  $fail */
    private function account(mixed $value, Closure $fail): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value >= 1000 && (int) $value <= 9999 ? (int) $value : $fail();
    }
}
