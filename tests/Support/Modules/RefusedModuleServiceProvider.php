<?php

declare(strict_types=1);

namespace Tests\Support\Modules;

use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * A module provider whose module the registry refuses: it claims a core
 * permission and gives it to the auditor. Its boot() would add a route and
 * a listener; the registry check must stop the boot before that runs.
 */
final class RefusedModuleServiceProvider extends ServiceProvider
{
    public static bool $booted = false;

    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new DummyModule(
            permissions: ['dummy:view', 'vouchers:post'],
            roles: [],
            rolePermissions: ['auditor' => ['vouchers:post']],
        ));
    }

    public function boot(): void
    {
        self::$booted = true;

        Route::get('/refused-module', fn (): string => 'Ska inte finnas');
        Event::listen('refused-module.event', fn (): null => null);
    }
}
