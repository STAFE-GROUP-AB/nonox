<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Middleware;

use App\Modules\Manufacturing\Actions\Workshop\WorkshopSessions;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind the device middleware: the worker signed in at the tablet proves
 * it with the session token in X-Workshop-Session (ADR-071 §7). A missing,
 * expired or forged token answers 401 and the tablet returns to the
 * picture screen.
 */
class AuthenticateWorkshopPerson
{
    public const string ATTRIBUTE = 'workshopPerson';

    public const string HEADER = 'X-Workshop-Session';

    public function __construct(private readonly WorkshopSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->attributes->get(AuthenticateWorkshopDevice::ATTRIBUTE);
        $person = $device instanceof WorkshopDevice ? $this->sessions->person($device, (string) $request->header(self::HEADER)) : null;

        if ($person === null) {
            return response()->json(['error' => 'session_ended'], 401);
        }

        $request->attributes->set(self::ATTRIBUTE, $person);

        return $next($request);
    }
}
