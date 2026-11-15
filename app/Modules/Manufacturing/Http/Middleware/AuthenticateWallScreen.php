<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Middleware;

use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Domain\DeviceKind;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wall screen requests carry the screen's device token as a bearer token;
 * nobody is signed in (ADR-071, M7). Only a wall screen's token counts: a
 * terminal tablet's does not. The token's device sets the company for the
 * request. A revoked or unknown screen answers 401, a company with the
 * module switched off 404. The terminal setting does not matter: the wall
 * screen reports nothing and shows no person.
 */
class AuthenticateWallScreen
{
    public const string ATTRIBUTE = 'wallScreen';

    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = WorkshopDevices::forToken((string) $request->bearerToken(), DeviceKind::WallScreen);

        if ($device === null) {
            return response()->json(['error' => 'device_unknown'], 401);
        }

        CurrentCompany::set($device->company_id);

        if (! $this->modules->isEnabled(ManufacturingModule::KEY, $device->company_id)) {
            return response()->json(['error' => 'module_off'], 404);
        }

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }
}
