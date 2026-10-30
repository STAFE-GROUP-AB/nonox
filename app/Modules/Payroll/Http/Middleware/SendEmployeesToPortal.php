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
    /**
     * Routes an employee may always reach: logging out, accepting invitations, switching company, and Livewire
     * itself, whose update route Livewire 4 names "default-livewire.update". The portal and the company logo it
     * shows are theirs while payroll is on.
     */
    private const array ALWAYS = ['logout', 'livewire.*', '*-livewire.*', 'team-invitations.*', 'current-team.update'];

    private const array PORTAL = ['payroll.portal.*', 'payroll.imported-payslip', 'teams.logo'];

    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if (! $user instanceof User || $route === null || ! is_string($route->getName()) || $request->routeIs(...self::ALWAYS)) {
            return $next($request);
        }

        $company = $user->currentCompany();

        if (! $company instanceof Company || $user->teamRole($company)?->key !== 'employee') {
            return $next($request);
        }

        // With payroll off, someone who is only an employee has nothing to open here (PAYROLL_MODULE.md A.6).
        if (! $this->modules->isEnabled(PayrollModule::KEY, $company)) {
            return response()->view('payroll::portal.closed', ['company' => $company, 'user' => $user], Response::HTTP_FORBIDDEN);
        }

        if ($request->routeIs(...self::PORTAL)) {
            return $next($request);
        }

        return redirect()->route('payroll.portal.home');
    }
}
