<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Locks the bookkeeping up to and including a date (AGENTS.md §1.2), for
 * example after the VAT for a period has been declared. Nothing can be
 * posted in a locked period. A lock only ever moves forward.
 *
 * The company row is locked for update, which conflicts with the key-share
 * lock PostVoucher holds on it: a posting in progress finishes before the
 * lock moves, and a posting that starts afterwards sees the new lock.
 */
class LockPeriod
{
    public function __invoke(User $user, Company $company, CarbonImmutable $until): void
    {
        if (! $user->hasTeamPermission($company, 'periods:lock')) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($user, $company, $until): void {
            /** @var Company $locked */
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $current = $locked->locked_until_date;

            if ($current !== null && $until->lessThanOrEqualTo($current)) {
                throw ValidationException::withMessages(['locked_until_date' => __('Bokföringen är redan låst till och med :date.', ['date' => $current->toDateString()])]);
            }

            $locked->forceFill(['locked_until_date' => $until->toDateString()])->save();
            $company->setRawAttributes($locked->getAttributes(), true);

            Audit::record('period.locked', $company, [
                'before' => $current?->toDateString(),
                'after' => $until->toDateString(),
            ], $company, $user);
        });
    }
}
