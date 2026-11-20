<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceFloorPolicy;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the company's lowest contribution margin and what a manual price under
 * it does (ADR-082 §9, decision 12). Off when no percentage is given. Needs
 * price-lists:manage: whoever sets the prices decides how they are guarded.
 */
class SetPriceFloor
{
    /**
     * @param  ?string  $percent  "15" or "12,5"; null or empty switches the floor off
     */
    public function __invoke(User $user, Company $company, ?string $percent, PriceFloorPolicy $policy): Company
    {
        if (! $user->hasTeamPermission($company, 'price-lists:manage') || $company->isArchived()) {
            throw new AuthorizationException(__('Du har inte behörighet att ändra lägsta täckningsgrad.'));
        }

        $bp = self::parse($percent);

        return DB::transaction(function () use ($user, $company, $bp, $policy): Company {
            $company->forceFill(['min_contribution_bp' => $bp, 'price_floor_policy' => $policy])->save();

            Audit::record('price_floor.changed', $company, ['min_contribution_bp' => $bp, 'policy' => $policy->value], $company, $user);

            return $company;
        });
    }

    /** A percentage as typed to basis points, 0 to 99 %, at most two decimals; null for nothing. */
    public static function parse(?string $percent): ?int
    {
        $value = str_replace([',', ' '], ['.', ''], trim((string) $percent));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $value) !== 1) {
            throw ValidationException::withMessages(['percent' => __('Skriv täckningsgraden i procent mellan 0,01 och 99, till exempel 15 eller 12,5. Lämna fältet tomt för att stänga av.')]);
        }

        $bp = (int) round((float) $value * 100);

        // A floor of 0 % would let anyone read a cost off the warnings: below zero is below cost, and nothing else.
        if ($bp < 1) {
            throw ValidationException::withMessages(['percent' => __('Skriv täckningsgraden i procent mellan 0,01 och 99, till exempel 15 eller 12,5. Lämna fältet tomt för att stänga av.')]);
        }

        return $bp;
    }
}
