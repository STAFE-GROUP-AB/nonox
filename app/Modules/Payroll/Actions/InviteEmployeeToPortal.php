<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Mail\EmployeePortalInvitationMail;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Invites an employee to the employee portal (Min sida) with the role
 * `employee`. The email explains how to log in with a one-time code; when
 * the invitation is accepted the login is linked to the employee. Someone
 * who is already a member of the company is linked at once.
 */
class InviteEmployeeToPortal
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @return bool true when an invitation was sent, false when an existing member was linked
     */
    public function __invoke(User $user, Company $company, Employee $employee): bool
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Employee::class, $company]);

        if (! ($this->modules->settings(PayrollModule::KEY, $company)['employee_self_service'] ?? true)) {
            throw ValidationException::withMessages(['portal' => __('Självservice för anställda är avstängd i löneinställningarna.')]);
        }

        if ($employee->user_id !== null) {
            throw ValidationException::withMessages(['portal' => __(':name har redan en inloggning.', ['name' => $employee->first_name])]);
        }

        $email = trim((string) $employee->email);

        if ($email === '') {
            throw ValidationException::withMessages(['portal' => __('Fyll i e-postadressen för :name först. Inbjudan skickas dit.', ['name' => $employee->first_name])]);
        }

        $member = $company->allUsers()->first(fn (User $candidate): bool => strcasecmp($candidate->email, $email) === 0);

        if ($member instanceof User) {
            return CurrentCompany::run($company, function () use ($user, $company, $employee, $member): bool {
                if (Employee::query()->where('user_id', $member->id)->exists()) {
                    throw ValidationException::withMessages(['portal' => __('Inloggningen hör redan till en annan anställd.')]);
                }

                $employee->forceFill(['user_id' => $member->id])->save();
                Audit::record('payroll.employee_login_linked', $employee, ['employee_number' => $employee->employee_number], $company, $user);

                return false;
            });
        }

        $invitation = DB::transaction(function () use ($user, $company, $employee, $email): TeamInvitation {
            TeamInvitation::query()->where('team_id', $company->id)->whereRaw('lower(email) = ?', [mb_strtolower($email)])->delete();

            /** @var TeamInvitation $invitation */
            $invitation = $company->teamInvitations()->create(['email' => $email, 'role' => 'employee', 'name' => trim("{$employee->first_name} {$employee->last_name}")]);

            CurrentCompany::run($company, fn () => $employee->forceFill(['portal_invited_at' => now()])->save());
            Audit::record('payroll.employee_invited_to_portal', $employee, ['employee_number' => $employee->employee_number], $company, $user);

            return $invitation;
        });

        Mail::to($email)->send(new EmployeePortalInvitationMail($invitation, $employee->first_name));

        return true;
    }
}
