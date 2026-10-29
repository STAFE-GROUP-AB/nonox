<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingController
{
    public function show(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->companies()->isNotEmpty()) {
            return redirect()->route('dashboard');
        }

        return view('onboarding', [
            'invitations' => TeamInvitation::with('team')
                ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
                ->latest()
                ->get(),
        ]);
    }
}
