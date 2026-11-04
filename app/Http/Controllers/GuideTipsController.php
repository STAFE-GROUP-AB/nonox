<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Hides or shows Företagsguiden's tips on the pages of the app (ADR-051),
 * for this user and the current company only.
 */
class GuideTipsController
{
    public const string PANEL = 'guide';

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company $company */
        $company = $user->currentCompany();

        $hidden = ! $request->boolean('visible');
        $user->setPanelHidden(self::PANEL, $company, $hidden);

        Toast::flash($hidden
            ? __('Tipsen från Företagsguiden är dolda. Du kan visa dem igen under Min startplan.')
            : __('Tipsen från Företagsguiden visas igen.'));

        return redirect()->back(fallback: route('guide.plan'));
    }
}
