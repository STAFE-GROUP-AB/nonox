<?php

declare(strict_types=1);

namespace App\Migration\Import;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Moving registers, open invoices and underlag in from another program
 * (ADR-076): the routes and views of "Läs in register" and the per-program
 * moving guides. The permission is import:registers. No program is named in
 * code; names live in docs/migration (presets and guides).
 */
class ImportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'import');

        Route::middleware('web')->group(__DIR__.'/routes.php');
    }
}
