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
use Illuminate\Support\Str;

/**
 * Makes the employee's secret calendar link (PLANNING_MODULE.md §9): a
 * random token of which only the SHA-256 hash is kept. A new link replaces
 * the old one, which stops working. Returns the full feed URL, shown once.
 */
final class IssueIcalToken
{
    public function __construct(private readonly SelfService $self) {}

    public function __invoke(User $user, Company $company): string
    {
        $employee = $this->self->employee($user, $company);
        $token = Str::random(48);

        CurrentCompany::run($company, function () use ($user, $company, $employee, $token): void {
            DB::transaction(function () use ($user, $company, $employee, $token): void {
                $revoked = IcalToken::query()->where('employee_id', $employee->id)->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]);

                $issued = IcalToken::create([
                    'employee_id' => $employee->id,
                    'token_hash' => IcalToken::hash($token),
                    'created_by' => $user->id,
                ]);

                Audit::record('planning.ical_issued', $issued, ['employee_id' => $employee->id, 'replaced' => $revoked], $company, $user);
            });
        });

        return route('planning.ical', ['token' => $token]);
    }
}
