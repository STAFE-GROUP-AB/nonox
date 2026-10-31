<?php

declare(strict_types=1);

namespace App\Migration\Fortnox;

use App\Migration\Fortnox\Livewire\FortnoxMovePage;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * The move from Fortnox (AGENTS.md §14): its routes, views, page and the
 * permission import:fortnox. Everything that names Fortnox lives in this
 * directory (§1.1).
 */
class FortnoxServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'fortnox');

        Gate::define('importFortnox', fn (User $user, Company $company): bool => $user->hasTeamPermission($company, 'import:fortnox'));

        Livewire::component(FortnoxMovePage::NAME, FortnoxMovePage::class);

        Route::middleware('web')->group(__DIR__.'/routes.php');
    }
}
