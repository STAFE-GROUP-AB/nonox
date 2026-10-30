<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone whose only role in the current company is `employee` uses the
 * employee portal and nothing else: every other page sends them there.
 */
class SendEmployeesToPortal
{
    /** Routes an employee may still reach: the portal, logging out, accepting invitations, Livewire itself. */
    private const array ALLOWED = ['payroll.portal.*', 'logout', 'livewire.*', 'team-invitations.*', 'current-team.update'];

    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if (! $user instanceof User || $route === null || ! is_string($route->getName()) || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        $company = $user->currentCompany();

        if (! $company instanceof Company || $user->teamRole($company)?->key !== 'employee' || ! $this->modules->isEnabled(PayrollModule::KEY, $company)) {
            return $next($request);
        }

        return redirect()->route('payroll.portal.home');
    }
}
