<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Domain\Projects\FixedPriceMethods;
use App\Models\Company;
use App\Models\User;
use App\Modules\WorkOrders\Domain\FixedPriceMethod;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sets the company's one method for all fixed-price jobs at year end (K2
 * 6.15, K3 23.31; ADR-081 E8), for work orders and projects alike. It is an
 * accounting principle, so only whoever keeps the fiscal years
 * (`fiscal-years:manage`) may change it. The audit entry holds the two enums
 * only.
 */
class SetFixedPriceMethod
{
    /** @return bool whether the method changed */
    public function __invoke(User $user, Company $company, FixedPriceMethod $method): bool
    {
        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($user, $company, $method): bool {
            /** @var Company $locked */
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $before = FixedPriceMethods::for($locked);
            $stored = $locked->getAttribute('fixed_price_method');

            if ($stored === $method->value) {
                return false;
            }

            $locked->forceFill(['fixed_price_method' => $method->value])->save();
            $company->setAttribute('fixed_price_method', $method->value);

            if ($before !== $method) {
                Audit::record('company.fixed_price_method_changed', $locked, ['before' => $before->value, 'after' => $method->value], $locked, $user);
            }

            return $before !== $method;
        });
    }
}
