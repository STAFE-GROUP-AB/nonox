<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Middleware;

use App\Models\Company;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminal requests carry the device token as a bearer token; nobody is
 * signed in (ADR-071 §7, as the staff ledger's kiosk, ADR-022). The token's
 * device sets the company for the request, so a paired tablet can reach
 * only its own company. A revoked or unknown device answers 401, a company
 * with the module or the terminal switched off 404.
 */
class AuthenticateWorkshopDevice
{
    public const string ATTRIBUTE = 'workshopDevice';

    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = WorkshopDevices::forToken((string) $request->bearerToken());

        if ($device === null) {
            return response()->json(['error' => 'device_unknown'], 401);
        }

        CurrentCompany::set($device->company_id);

        if (! $this->modules->isEnabled(ManufacturingModule::KEY, $device->company_id)) {
            return response()->json(['error' => 'module_off'], 404);
        }

        $company = CompanyScope::bypass(fn (): ?Company => Company::query()->find($device->company_id));

        if (! $company instanceof Company || ! ManufacturingSettings::terminalEnabled($company)) {
            return response()->json(['error' => 'terminal_off'], 404);
        }

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }
}
