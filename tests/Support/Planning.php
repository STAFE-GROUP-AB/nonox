<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;

/**
 * Planering test setup: payroll on (with its rules verified inside the
 * test), Planering on, and synthetic employees with Luhn-valid personal
 * numbers.
 */
final class Planning
{
    public static function setUp(Company $company, User $owner): void
    {
        Payroll::setUp($company, $owner);
        app(ModuleManager::class)->enable(PlanningModule::KEY, $company, $owner);
        CurrentCompany::set($company);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function employee(User $owner, Company $company, string $first = 'Anna', string $nine = '900615123', array $overrides = []): Employee
    {
        return Payroll::employee($owner, $company, [
            'first_name' => $first,
            'personal_number' => '19'.$nine.Luhn::checkDigit($nine),
            ...$overrides,
        ]);
    }

    /** @param  array<string, mixed>  $input */
    public static function shift(User $owner, Company $company, array $input): Shift
    {
        return CurrentCompany::run($company, fn (): Shift => app(SaveShift::class)($owner, $company, $input));
    }
}
