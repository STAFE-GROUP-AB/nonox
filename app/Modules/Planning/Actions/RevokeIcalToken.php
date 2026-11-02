<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Models\IcalToken;
use App\Modules\Planning\Support\SelfService;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stops the employee's calendar link at once. Audited.
 */
final class RevokeIcalToken
{
    public function __construct(private readonly SelfService $self) {}

    /** @return bool whether there was a link to stop */
    public function __invoke(User $user, Company $company): bool
    {
        $employee = $this->self->employee($user, $company);

        return CurrentCompany::run($company, fn (): bool => DB::transaction(function () use ($user, $company, $employee): bool {
            $tokens = IcalToken::query()->where('employee_id', $employee->id)->whereNull('revoked_at')->lockForUpdate()->get();

            foreach ($tokens as $token) {
                $token->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
                Audit::record('planning.ical_revoked', $token, ['employee_id' => $employee->id], $company, $user);
            }

            return $tokens->isNotEmpty();
        }));
    }
}
