<?php

declare(strict_types=1);

namespace App\Modules\Portal\Http\Middleware;

use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in portal identity (guard portal), within the session limits
 * (PortalSession). Otherwise to the sign-in page; a portal address visited
 * signed out remembers its key, so the sign-in page carries that company's
 * brand.
 */
final class AuthenticatePortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $identity = Auth::guard('portal')->user();

        if ($identity instanceof PortalIdentity && ! PortalSession::expired($request, $identity)) {
            return $next($request);
        }

        $key = $request->route('portalKey');

        if ($identity instanceof PortalIdentity) {
            PortalSession::end($request);
        }

        // A portal address of a company whose portal is closed (module off, archived, unknown) answers as unknown.
        if (is_string($key)) {
            abort_unless(app(PortalAccess::class)->openSettingsFor($key) !== null, 404);
            $request->session()->put(PortalSession::BRAND_KEY, $key);
        }

        return redirect()->route('portal.login');
    }
}
