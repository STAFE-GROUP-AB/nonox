<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Support\Demo\DemoMode;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class LogoutController
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Also a guest's: the demo cookie goes with the session (ADR-077).
        Cookie::queue(Cookie::forget(DemoMode::COOKIE));

        Toast::flash(__('Du är utloggad.'));

        return redirect('/');
    }
}
