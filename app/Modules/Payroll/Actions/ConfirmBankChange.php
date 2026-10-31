<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Confirms or withdraws a changed salary account (PAYROLL_MODULE.md B.11).
 * Confirming takes another person than the one who changed it, when the
 * company has another member who may manage employees; in a company with
 * only one such person, that person confirms it as a separate, logged step.
 *
 * TODO(legal-verify): whether a one-person company needs further proof,
 * such as a fresh sign-in code, before a salary account changes.
 */
class ConfirmBankChange
{
    public function confirm(User $user, Company $company, Employee $employee): Employee
    {
        Gate::forUser($user)->authorize('manage', [Employee::class, $company]);

        return CurrentCompany::run($company, fn (): Employee => DB::transaction(function () use ($user, $company, $employee): Employee {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $pending = $employee->pending_bank ?? throw ValidationException::withMessages(['bank' => __('Det finns inget nytt bankkonto att bekräfta.')]);

            if ($employee->pending_bank_by === $user->id && $this->hasOtherManager($company, $user)) {
                throw ValidationException::withMessages(['bank' => __('Någon annan som hanterar personal måste bekräfta det nya kontot.')]);
            }

            $old = SaveEmployee::mask($employee->bank_account);
            $employee->forceFill([
                'bank_clearing' => $pending['bank_clearing'],
                'bank_account' => $pending['bank_account'],
                'pending_bank' => null,
                'pending_bank_by' => null,
                'pending_bank_at' => null,
            ])->save();

            Audit::record('payroll.bank_change_confirmed', $employee, ['old' => $old, 'new' => SaveEmployee::mask($employee->bank_account)], $company, $user);

            return $employee;
        }));
    }

    public function withdraw(User $user, Company $company, Employee $employee): void
    {
        Gate::forUser($user)->authorize('manage', [Employee::class, $company]);

        CurrentCompany::run($company, function () use ($user, $company, $employee): void {
            $employee->forceFill(['pending_bank' => null, 'pending_bank_by' => null, 'pending_bank_at' => null])->save();
            Audit::record('payroll.bank_change_withdrawn', $employee, [], $company, $user);
        });
    }

    private function hasOtherManager(Company $company, User $user): bool
    {
        $members = User::query()
            ->whereIn('id', [$company->user_id, ...$company->users()->pluck('users.id')->all()])
            ->whereKeyNot($user->id)
            ->with(['teams', 'ownedTeams'])
            ->get();

        return $members->contains(fn (User $member): bool => $member->hasTeamPermission($company, 'employees:manage'));
    }
}
