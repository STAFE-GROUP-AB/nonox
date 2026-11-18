<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Projects\ProjectPriceForm;
use App\Domain\Projects\ProjectStatus;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The register details of a project (ADR-081 gap 6): customer, project
 * manager, status, dates, price form, contract sum, estimated total cost and
 * default cost centre. The code and the name are SaveDimensionObject's. The
 * customer and the cost centre are looked up inside the company, and the
 * project manager must be a member of it. The audit entry holds field names,
 * ids, enums, dates and amounts only.
 */
class SaveProjectDetails
{
    /**
     * @param  array<string, mixed>  $input  customer_id, manager_user_id, status, starts_on, ends_on, price_form,
     *                                       contract_sum and estimated_cost (kronor as typed), default_cost_center (code)
     */
    public function __invoke(User $user, Company $company, Project $project, array $input): Project
    {
        Gate::forUser($user)->authorize('update', $project);

        $validated = Validator::make($input, [
            'customer_id' => ['nullable', 'integer'],
            'manager_user_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'price_form' => ['nullable', Rule::enum(ProjectPriceForm::class)],
            'contract_sum' => ['nullable', 'string', 'max:30'],
            'estimated_cost' => ['nullable', 'string', 'max:30'],
            'default_cost_center' => ['nullable', 'string', 'max:20'],
        ], [
            'ends_on.after_or_equal' => __('Slutdatumet kan inte vara före startdatumet.'),
        ], [
            'status' => __('status'),
            'starts_on' => __('startdatum'),
            'ends_on' => __('slutdatum'),
            'price_form' => __('prisform'),
        ])->validate();

        return DB::transaction(fn (): Project => CurrentCompany::run($company, function () use ($validated, $project, $company, $user): Project {
            $customerId = self::filledInt($validated['customer_id'] ?? null);

            if ($customerId !== null && ! Customer::query()->whereKey($customerId)->exists()) {
                throw ValidationException::withMessages(['customer_id' => __('Kunden finns inte.')]);
            }

            $managerId = self::filledInt($validated['manager_user_id'] ?? null);

            if ($managerId !== null && ! self::isMember($company, $managerId)) {
                throw ValidationException::withMessages(['manager_user_id' => __('Projektledaren måste vara medlem i företaget.')]);
            }

            $code = trim((string) ($validated['default_cost_center'] ?? ''));
            $costCenterId = null;

            if ($code !== '') {
                $costCenterId = CostCenter::query()->where('code', $code)->value('id')
                    ?? throw ValidationException::withMessages(['default_cost_center' => __('Kostnadsstället :code finns inte.', ['code' => $code])]);
            }

            $after = [
                'customer_id' => $customerId,
                'manager_user_id' => $managerId,
                'status' => (string) $validated['status'],
                'starts_on' => ($validated['starts_on'] ?? null) ?: null,
                'ends_on' => ($validated['ends_on'] ?? null) ?: null,
                'price_form' => ($validated['price_form'] ?? null) ?: null,
                'contract_sum_ore' => self::amount($validated['contract_sum'] ?? null, 'contract_sum'),
                'estimated_cost_ore' => self::amount($validated['estimated_cost'] ?? null, 'estimated_cost'),
                'default_cost_center_id' => $costCenterId === null ? null : (int) $costCenterId,
            ];
            $before = self::details($project);

            $project->update($after);

            Audit::record('project.updated', $project, [
                'code' => $project->code,
                ...Audit::changes($before, self::details($project)),
            ], $company, $user);

            return $project;
        }));
    }

    /**
     * Whether the user is the owner or a member of the company.
     */
    public static function isMember(Company $company, int $userId): bool
    {
        return $company->user_id === $userId || $company->users()->whereKey($userId)->exists();
    }

    /** @return array<string, int|string|null> */
    private static function details(Project $project): array
    {
        return [
            'customer_id' => $project->customer_id,
            'manager_user_id' => $project->manager_user_id,
            'status' => $project->status->value,
            'starts_on' => $project->starts_on?->toDateString(),
            'ends_on' => $project->ends_on?->toDateString(),
            'price_form' => $project->price_form?->value,
            'contract_sum_ore' => $project->contract_sum_ore?->ore,
            'estimated_cost_ore' => $project->estimated_cost_ore?->ore,
            'default_cost_center_id' => $project->default_cost_center_id,
        ];
    }

    private static function filledInt(mixed $value): ?int
    {
        return $value === null || $value === '' || (int) $value === 0 ? null : (int) $value;
    }

    private static function amount(mixed $value, string $field): ?int
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        try {
            $money = Money::parse($text);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 250 000 eller 250 000,00.')]);
        }

        if ($money->ore < 0) {
            throw ValidationException::withMessages([$field => __('Beloppet kan inte vara negativt.')]);
        }

        return $money->ore;
    }
}
