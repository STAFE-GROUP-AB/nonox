<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\FirstRun;
use App\Support\Landing\LandingPage;
use App\Support\SetupStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What "/" shows: the first-run guide on a new local installation, a plain
 * explanation when something is missing, otherwise the public landing page
 * (or the sign-in page where the landing page is turned off).
 */
class StartController
{
    public function __invoke(Request $request, SetupStatus $setup): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('dashboard');
        }

        $problem = $setup->problem();

        if ($problem !== null) {
            return view('start.problem', [
                'problem' => app()->isProduction() ? null : $problem,
            ]);
        }

        if (FirstRun::allowed()) {
            return view('start.setup');
        }

        if (User::query()->doesntExist()) {
            return view('start.not-set-up');
        }

        if (config('app.landing.enabled')) {
            return view('start.landing', LandingPage::data());
        }

        return view('start.welcome');
    }
}
