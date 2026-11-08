<?php

declare(strict_types=1);

namespace App\Domain\Mileage;

use App\Domain\Company\LegalForm;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;

/**
 * Who may use the mileage log (ADR-072 §1). The company setting Körjournal
 * (off by default) shows or hides every screen; turning it off keeps the
 * data. Staff with mileage:view see the log, mileage:manage keeps the
 * company's cars and the year readings; an employee logs and claims their
 * own trips with the payroll permission self:expenses and sees nothing else;
 * the owner of an enskild firma logs their own trips.
 */
final class MileageLog
{
    public static function enabled(Company $company): bool
    {
        return $company->mileage_log_enabled && ! $company->personal_team;
    }

    /** The company's pages: staff with mileage:view, or the owner of an enskild firma. */
    public static function canView(User $user, Company $company): bool
    {
        return self::enabled($company) && ($user->hasTeamPermission($company, 'mileage:view') || self::isSoleTraderOwner($user, $company));
    }

    public static function canManage(User $user, Company $company): bool
    {
        return self::enabled($company) && $user->hasTeamPermission($company, 'mileage:manage');
    }

    /** An employee's own log on Min sida, while payroll is on. */
    public static function canLogAsEmployee(User $user, Company $company): bool
    {
        return self::enabled($company)
            && app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company)
            && $user->hasTeamPermission($company, 'self:expenses');
    }

    /** The owner of an enskild firma books their own trips (IL 16 kap. 27 §); HB and KB partners are out (KJ-R16). */
    public static function isSoleTraderOwner(User $user, Company $company): bool
    {
        return $company->legal_form === LegalForm::Ef && $user->ownsTeam($company);
    }

    /** Whether the person may log trips in the vehicle: their own car, or any of the company's cars. */
    public static function maySelectVehicle(User $user, Vehicle $vehicle): bool
    {
        if ($vehicle->archived_at !== null) {
            return false;
        }

        return $vehicle->isCompanyCar() || $vehicle->user_id === $user->id;
    }
}
