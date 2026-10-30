<?php

declare(strict_types=1);

namespace App\Modules\Http\Middleware;

use App\Modules\ModuleManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard "module:payroll": a module's routes answer 404 while the
 * module is off for the current company, so its existence is not shown.
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        abort_unless($this->modules->isEnabled($key), 404);

        return $next($request);
    }
}
