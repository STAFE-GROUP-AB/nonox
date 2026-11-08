<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Actions\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Gate;

/**
 * Every Kök action starts here: the module must be on for the company and
 * the user must hold the ability (KitchenPolicy).
 */
trait GuardsKitchen
{
    private function guard(User $user, Company $company, string $ability): void
    {
        app(ModuleManager::class)->ensureEnabled(KitchenModule::KEY, $company);
        Gate::forUser($user)->authorize($ability, [WasteEntry::class, $company]);
    }
}
