<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

/**
 * Sign-in is passwordless (ADR-000) and handled by App\Livewire\Auth\LoginForm.
 * Fortify's routes (English paths, password flows) are switched off; only its
 * profile update action is still used by Jetstream's profile page.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
    }
}
