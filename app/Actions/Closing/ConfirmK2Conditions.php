<?php

declare(strict_types=1);

namespace App\Actions\Closing;

use App\Domain\Closing\K2Reconfirmation;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that a person confirmed the K2 conditions (BFNAR 2016:10 punkt
 * 1.1A-1.1C as amended by BFNAR 2025:2) for the company, once (owner
 * decision 16). Allowed for whoever manages the fiscal years, the permission
 * the closing screen itself requires. A second call changes nothing.
 */
class ConfirmK2Conditions
{
    public function __invoke(User $user, FiscalYear $fiscalYear): Company
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        if (! K2Reconfirmation::applies($company, $fiscalYear)) {
            throw ValidationException::withMessages(['k2' => __('K2-villkoren behöver inte bekräftas för det här räkenskapsåret.')]);
        }

        return DB::transaction(function () use ($user, $company, $fiscalYear): Company {
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            if ($locked->k2_conditions_confirmed_at !== null) {
                return $locked;
            }

            $locked->forceFill(['k2_conditions_confirmed_at' => now(), 'k2_conditions_confirmed_by' => $user->id])->save();
            Audit::record('company.k2_conditions_confirmed', $locked, [
                'fiscal_year_id' => $fiscalYear->id,
                'legal_form' => $locked->legal_form->value,
            ], $locked, $user);

            return $locked;
        });
    }
}
