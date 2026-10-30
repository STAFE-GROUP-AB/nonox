<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Hides or shows the "Kom igång" panel on the overview, for this user and
 * the current company only.
 */
class GettingStartedController
{
    public const string PANEL = 'getting-started';

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company $company */
        $company = $user->currentCompany();

        $hidden = ! $request->boolean('visible');
        $user->setPanelHidden(self::PANEL, $company, $hidden);

        Toast::flash($hidden
            ? __('Kom igång är dold. Du kan visa den igen längst ner på översikten.')
            : __('Kom igång visas igen.'));

        return redirect()->route('dashboard');
    }
}
