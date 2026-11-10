<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use App\Support\Landing\LandingPage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Prova utan att registrera" (/prova, ADR-077): a visitor gets a demo
 * company of their own and a guest user, without an account. The page is
 * public and guarded without a third-party CAPTCHA: a hidden field real
 * visitors never fill in, a minimum time to fill the form in, a limit per
 * IP address and hour, and a ceiling on live demos. The IP address is in
 * the cache only, as a keyed hash, for an hour.
 */
class DemoController
{
    public function show(): View|RedirectResponse
    {
        abort_unless(DemoMode::enabled(), 404);

        if (Auth::check() && DemoMode::active()) {
            return redirect()->route('dashboard');
        }

        if (Auth::check()) {
            return view('start.try-signed-in', [
                ...LandingPage::data(),
                'pageTitle' => 'Prova NONOX som gäst – NONOX',
                'pageDescription' => 'Demon körs som en egen gästsession, skild från ditt konto.',
            ]);
        }

        return view('start.try', [
            ...LandingPage::data(),
            'industries' => DemoCatalog::industries(),
            'hours' => (int) config('nonox.demo.hours'),
            'started' => Crypt::encryptString((string) time()),
            'pageTitle' => 'Prova NONOX utan att registrera dig',
            'pageDescription' => 'Prova NONOX på ett påhittat företag med ett års bokföring, fakturor och lön. Inget konto, ingen e-postadress, och inget skickas till någon.',
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        abort_unless(DemoMode::enabled(), 404);

        if (Auth::check()) {
            return DemoMode::active() ? redirect()->route('dashboard') : redirect()->route('demo.show');
        }

        $limiter = DemoSessions::limiter();
        $botKey = 'demo-bot:'.$this->visitor($request);
        $startKey = 'demo-start:'.$this->visitor($request);

        abort_if($limiter->tooManyAttempts($botKey, 10), 429);

        if (! $this->looksHuman($request)) {
            $limiter->hit($botKey, 3600);

            throw ValidationException::withMessages(['industry' => __('Det gick inte att starta demon. Ladda om sidan och försök igen.')]);
        }

        if ($limiter->tooManyAttempts($startKey, (int) config('nonox.demo.max_per_ip_per_hour'))) {
            throw ValidationException::withMessages(['industry' => __('Du har startat flera demor den senaste timmen. Vänta en stund, eller fortsätt i en som redan är igång.')]);
        }

        $token = DemoSessions::begin((string) $request->input('industry'), $request->session()->getId());

        $limiter->hit($startKey, 3600);

        return redirect()->route('demo.building', $token);
    }

    /**
     * The wait. Runs on the main database like every public page; the state is in the demo database's cache and
     * belongs to the session that started the build. A page with a script polls status(); this answer is also what the
     * "Kontrollera igen" button gives without one.
     */
    public function building(Request $request, string $token): View|RedirectResponse
    {
        abort_unless(DemoMode::enabled(), 404);

        $state = DemoSessions::stateFor($token, $request->session()->getId());

        if ($state === null) {
            return redirect()->route('demo.show');
        }

        if ($state['status'] === DemoSessions::FAILED) {
            return redirect()->route('demo.show')->withErrors(['industry' => __('Det gick inte att bygga demon. Försök igen om en stund.')]);
        }

        if ($state['status'] === DemoSessions::READY) {
            $grant = DemoSessions::grant($token, $request->session()->getId());

            return $grant === null ? redirect()->route('demo.show') : redirect()->route('demo.open', $grant);
        }

        return view('start.try-building', [
            ...LandingPage::data(),
            'token' => $token,
            'pageTitle' => 'Vi bygger ditt demoföretag – NONOX',
            'pageDescription' => 'Ett påhittat företag med ett års bokföring byggs åt dig.',
        ]);
    }

    /** What the waiting page's script asks every two seconds: still building, or where to go. */
    public function status(Request $request, string $token): JsonResponse
    {
        abort_unless(DemoMode::enabled(), 404);

        $state = DemoSessions::stateFor($token, $request->session()->getId());
        $headers = ['Cache-Control' => 'no-store'];

        if ($state === null || $state['status'] === DemoSessions::FAILED) {
            return response()->json(['status' => 'failed', 'url' => route('demo.show')], 200, $headers);
        }

        if ($state['status'] === DemoSessions::READY) {
            $grant = DemoSessions::grant($token, $request->session()->getId());

            return response()->json(['status' => $grant === null ? 'failed' : 'ready', 'url' => $grant === null ? route('demo.show') : route('demo.open', $grant)], 200, $headers);
        }

        return response()->json(['status' => 'building'], 200, $headers);
    }

    /**
     * Opens the demo: a guest request (UseDemoDatabase), so the session that is
     * started here is in the demo database under the guest's own cookie. The
     * token works once.
     */
    public function open(Request $request, string $token): RedirectResponse
    {
        abort_unless(DemoMode::enabled(), 404);
        // Not a guest's request (UseDemoDatabase found no ready token): nothing to open.
        if (! DemoMode::active()) {
            return redirect()->route('demo.show');
        }

        $guest = DemoSessions::claim($token);

        if ($guest === null) {
            return redirect()->route('demo.show');
        }

        Auth::guard('web')->login($guest);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /** The honeypot is empty and the form has been open for a person's time, but not for too long. */
    private function looksHuman(Request $request): bool
    {
        if ($request->filled('website')) {
            return false;
        }

        try {
            $opened = (int) Crypt::decryptString((string) $request->input('started'));
        } catch (DecryptException) {
            return false;
        }

        $age = time() - $opened;

        return $age >= (int) config('nonox.demo.min_seconds') && $age <= 3600;
    }

    /** A keyed hash of the address: what the limits count by, not the address itself. */
    private function visitor(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
    }
}
