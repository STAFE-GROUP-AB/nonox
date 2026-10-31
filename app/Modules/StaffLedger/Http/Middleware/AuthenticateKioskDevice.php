<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Http\Middleware;

use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Actions\KioskDevices;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kiosk requests carry the device token as a bearer token; nobody is signed
 * in. The token's device sets the company for the request. A revoked or
 * unknown device answers 401, a company with the module off 404.
 */
class AuthenticateKioskDevice
{
    public const string ATTRIBUTE = 'staffLedgerDevice';

    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = KioskDevices::forToken((string) $request->bearerToken());

        if ($device === null) {
            return response()->json(['error' => 'device_unknown'], 401);
        }

        CurrentCompany::set($device->company_id);

        if (! $this->modules->isEnabled(StaffLedgerModule::KEY, $device->company_id)) {
            return response()->json(['error' => 'module_off'], 404);
        }

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }
}
