<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Firm\FirmCache;
use App\Domain\Tasks\MonthCloseStep;
use App\Models\Company;
use App\Models\MonthCloseCheck;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Checks a step of a client's month off by hand on Månadsstängning, or
 * undoes it (ADR-028). Needs tasks:manage in the company.
 *
 * @return bool true when the step is now checked
 */
class ToggleMonthCloseStep
{
    public function __invoke(User $actor, Company $company, string $month, MonthCloseStep $step): bool
    {
        if (! $actor->hasTeamPermission($company, 'tasks:manage')) {
            throw new AuthorizationException;
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => __('Ogiltig månad.')]);
        }

        $checked = CurrentCompany::run($company, function () use ($actor, $company, $month, $step): bool {
            $existing = MonthCloseCheck::query()->where('month', $month)->where('step', $step->value)->first();

            if ($existing instanceof MonthCloseCheck) {
                $existing->delete();
                Audit::record('month_close.unchecked', null, ['month' => $month, 'step' => $step->value], $company, $actor);

                return false;
            }

            MonthCloseCheck::query()->create(['month' => $month, 'step' => $step->value, 'checked_by' => $actor->id, 'checked_at' => now()]);
            Audit::record('month_close.checked', null, ['month' => $month, 'step' => $step->value], $company, $actor);

            return true;
        });

        FirmCache::bump($company->id);

        return $checked;
    }
}
