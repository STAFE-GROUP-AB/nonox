<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\Demo\DemoMode;
use App\Support\Hq\HqAccess;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Who must sign in with a passkey after the email code (ADR-029). With an
 * email code alone, whoever takes over a mailbox takes over the account; for
 * those who can read health data (sick leave) or full personal numbers that
 * is not enough (GDPR art. 32).
 */
final class PasskeyRequirement
{
    /**
     * Permissions that reveal health data or full personal numbers. Holding
     * any of them in any company makes a passkey required.
     */
    public const array SENSITIVE_PERMISSIONS = [
        // Absence causes: sick leave, care of a sick child (PAYROLL_MODULE.md B.15).
        'payroll:view-sensitive',
        // Payroll runs and payslips: sick pay and qualifying-day deduction lines show who was ill.
        'payroll:view',
        // The same runs and payslips in the payroll archive.
        'payroll:archive-view',
        // Staff ledger personal numbers shown in full.
        'staff-ledger:view-sensitive',
        // The control visit view shows every personal number in full.
        'staff-ledger:control-visit',
    ];

    /**
     * Whether the user must use a passkey: they hold a sensitive permission
     * in one of their companies, or a company they belong to requires
     * passkeys for everyone. A module's permission counts only in a company
     * where the module has been turned on at some point; before that the
     * module has no data to show.
     */
    public static function appliesTo(User $user): bool
    {
        // An HQ admin reads every company and user of the instance (ADR-038): never on an email code alone (security review 2026-11-04).
        if (HqAccess::allows($user)) {
            return true;
        }

        // Members are loaded once for all companies: the permission check reads each company's members.
        $companies = (new EloquentCollection($user->companies()->all()))->loadMissing('users');

        if ($companies->isEmpty()) {
            return false;
        }

        if ($companies->contains(fn (Company $company): bool => (bool) $company->require_passkeys)) {
            return true;
        }

        $registry = app(ModuleRegistry::class);
        $usedModules = self::usedModules(array_values($companies->map(fn (Company $company): int => $company->id)->all()));

        foreach ($companies as $company) {
            foreach (self::SENSITIVE_PERMISSIONS as $permission) {
                $module = $registry->moduleForPermission($permission);

                if ($module !== null && ! in_array($module->key(), $usedModules[$company->id] ?? [], true)) {
                    continue;
                }

                if ($user->hasTeamPermission($company, $permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the user must pass the passkey step at sign-in: the rule
     * applies to them, or they have added a passkey of their own accord.
     */
    public static function mustVerify(User $user): bool
    {
        // The demo's data is invented and a guest has no passkey to give (ADR-077).
        if (DemoMode::active()) {
            return false;
        }

        return $user->hasPasskeysEnabled() || self::appliesTo($user);
    }

    /**
     * The modules each company has turned on at some point.
     *
     * @param  list<int>  $companyIds
     * @return array<int, list<string>>
     */
    private static function usedModules(array $companyIds): array
    {
        // The user's own companies, whichever of them is current.
        $rows = CompanyScope::bypass(fn () => CompanyModule::query()
            ->whereIn('company_id', $companyIds)
            ->whereNotNull('enabled_at')
            ->get(['company_id', 'module_key']));

        $used = [];

        foreach ($rows as $row) {
            $used[$row->company_id][] = $row->module_key;
        }

        return $used;
    }
}
