<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Toast::flash(__('Du är utloggad.'));

        return redirect('/');
    }
}
