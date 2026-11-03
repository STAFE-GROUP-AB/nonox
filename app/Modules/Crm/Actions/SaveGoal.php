<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\GoalMetric;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Sets a seller's goal for a month (ADR-043 stage 2), for those with
 * crm:settings. One goal per seller, month and metric: setting it again
 * replaces the target, and an empty target removes the goal. Order value
 * is given in kronor as typed and kept in öre.
 */
final class SaveGoal
{
    /**
     * @param  array<string, mixed>  $input  user_id, month (Y-m or a date), metric, target
     */
    public function __invoke(User $user, Company $company, array $input): ?Goal
    {
        Gate::forUser($user)->authorize('settings', [Goal::class, $company]);

        $validated = Validator::make($input, [
            'user_id' => ['required', 'integer', Rule::in(CompanyMembers::ids($company))],
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
            'metric' => ['required', Rule::enum(GoalMetric::class)],
            'target' => ['nullable'],
        ], [
            'user_id.in' => __('Säljaren måste vara med i företaget.'),
        ], ['target' => __('mål'), 'month' => __('månad')])->validate();

        $metric = GoalMetric::from((string) $validated['metric']);
        $month = CarbonImmutable::parse(substr((string) $validated['month'], 0, 7).'-01')->startOfMonth();
        $target = $this->target($metric, $validated['target'] ?? null);

        return DB::transaction(fn (): ?Goal => CurrentCompany::run($company, function () use ($user, $company, $validated, $metric, $month, $target): ?Goal {
            $goal = Goal::query()
                ->where('user_id', (int) $validated['user_id'])
                ->where('month', $month->toDateString())
                ->where('metric', $metric->value)
                ->first();

            if ($target === null) {
                if ($goal !== null) {
                    $goal->delete();
                    Audit::record('crm.goal_removed', null, ['goal_id' => $goal->id, 'user_id' => $goal->user_id, 'month' => $month->format('Y-m'), 'metric' => $metric->value], $company, $user);
                }

                return null;
            }

            $goal ??= new Goal(['user_id' => (int) $validated['user_id'], 'month' => $month->toDateString(), 'metric' => $metric]);
            $goal->fill(['target' => $target])->save();

            Audit::record('crm.goal_set', $goal, ['goal_id' => $goal->id, 'user_id' => $goal->user_id, 'month' => $month->format('Y-m'), 'metric' => $metric->value, 'target' => $target], $company, $user);

            return $goal;
        }));
    }

    private function target(GoalMetric $metric, mixed $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($metric->isMoney()) {
            try {
                $ore = Money::parse($value)->ore;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['target' => __('Skriv målet i kronor, till exempel 250 000.')]);
            }
        } else {
            $ore = ctype_digit($value) ? (int) $value : -1;
        }

        if ($ore < 0 || $ore > 1_000_000_000_000) {
            throw ValidationException::withMessages(['target' => $metric->isMoney() ? __('Skriv målet i kronor, till exempel 250 000.') : __('Skriv målet som ett heltal, till exempel 8.')]);
        }

        return $ore;
    }
}
