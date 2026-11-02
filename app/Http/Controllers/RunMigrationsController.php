<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SetupStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/**
 * "Uppdatera databasen nu" on the start page's problem screen: runs the
 * pending migrations for someone working on their own computer, so they need
 * not open a terminal. Only on a local installation, only from the same
 * computer (a loopback address), only through the signed link the page
 * shows, and only while migrations are pending. It runs `migrate` and
 * nothing else: never fresh, refresh, reset or wipe. On a server the page
 * keeps showing the command, and deploys run the migrations.
 */
class RunMigrationsController
{
    public function __invoke(Request $request, SetupStatus $setup): View
    {
        abort_unless(self::allowed($request), 404);
        abort_unless($request->hasValidSignature(), 404);

        $exitCode = Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        return view('start.migrated', [
            'ok' => $exitCode === 0 && $setup->problem() === null,
            'output' => $output,
        ]);
    }

    /** Whether this request may run migrations from the browser at all. */
    public static function allowed(Request $request): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        return in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }
}
