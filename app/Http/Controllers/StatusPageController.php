<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Status\ComponentState;
use App\Support\Landing\LandingPage;
use App\Support\Status\SecurityTxt;
use App\Support\Status\StatusSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The public status page at /status and the security.txt (ADR-078). The
 * page reads one cached snapshot; nothing on it depends on who asks.
 */
class StatusPageController
{
    public function status(): View
    {
        $snapshot = StatusSnapshot::get();
        $overall = ComponentState::from($snapshot['overall']);

        return view('start.status', [
            ...LandingPage::data(),
            'snapshot' => $snapshot,
            'overall' => $overall,
            'pageTitle' => 'Driftstatus – NONOX',
            'pageDescription' => 'Om tjänsten är i drift just nu, vilka störningar som har varit och hur bakgrundsjobb, nattlig kontroll av bokföringen och externa kopplingar mår.',
        ]);
    }

    public function securityTxt(): Response
    {
        $body = SecurityTxt::render();

        abort_if($body === null, 404);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** RFC 9116 section 3: the legacy location may redirect to the one under /.well-known/. */
    public function legacySecurityTxt(): RedirectResponse
    {
        return redirect('/.well-known/security.txt', 301);
    }
}
