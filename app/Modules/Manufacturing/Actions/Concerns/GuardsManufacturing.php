<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Gate;

/**
 * Every Tillverkning action starts here: the module must be on for the
 * company and the user must hold the ability (ManufacturingPolicy).
 */
trait GuardsManufacturing
{
    private function guard(User $user, Company $company, string $ability): void
    {
        app(ModuleManager::class)->ensureEnabled(ManufacturingModule::KEY, $company);
        Gate::forUser($user)->authorize($ability, [Operation::class, $company]);
    }
}
