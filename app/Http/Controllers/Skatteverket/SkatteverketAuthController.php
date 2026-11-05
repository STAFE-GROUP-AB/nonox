<?php

declare(strict_types=1);

namespace App\Http\Controllers\Skatteverket;

use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketAuth;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signing in with Skatteverket (ADR-054). The person is sent to
 * Skatteverket, proves who they are with e-legitimation (BankID or another),
 * and comes back here with a code. The flow lives in the session, works
 * once, belongs to the person who started it and lasts ten minutes (the
 * code itself lives five). The code is exchanged at once and never logged.
 */
class SkatteverketAuthController
{
    public const string SESSION = 'skatteverket.oauth';

    private const int FLOW_MINUTES = 10;

    public function start(Request $request): RedirectResponse
    {
        abort_unless(Skatteverket::isAvailableTo($request->user()), 404);

        /** @var User $user */
        $user = $request->user();
        $state = Str::random(40);

        $request->session()->put(self::SESSION, [
            'state' => $state,
            'user_id' => $user->id,
            'back' => self::safeBack((string) $request->query('tillbaka', '')),
            'at' => now()->getTimestamp(),
        ]);

        return redirect()->away(SkatteverketAuth::authorizeUrl($state, route('skatteverket.callback')));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(Skatteverket::isAvailableTo($request->user()), 404);

        /** @var User $user */
        $user = $request->user();
        $flow = (array) $request->session()->get(self::SESSION, []);
        $back = self::safeBack((string) ($flow['back'] ?? ''));

        $fresh = is_int($flow['at'] ?? null) && now()->getTimestamp() - $flow['at'] <= self::FLOW_MINUTES * 60;
        $belongs = is_string($flow['state'] ?? null) && hash_equals($flow['state'], (string) $request->query('state', '')) && ($flow['user_id'] ?? null) === $user->id;

        // Only the matching answer uses the flow up, so a stray request cannot end a sign-in in progress.
        if ($belongs) {
            $request->session()->forget(self::SESSION);
        }

        if (! $fresh || ! $belongs) {
            Toast::flash(__('Inloggningen hos Skatteverket gick inte att slutföra. Börja om.'), 'error');

            return redirect($back);
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            Toast::flash(__('Du loggade inte in hos Skatteverket. Ingenting har skickats.'), 'error');

            return redirect($back);
        }

        try {
            SkatteverketAuth::exchange($user, (string) $request->query('code'), route('skatteverket.callback'));
        } catch (SkatteverketFailed $exception) {
            report($exception);
            Toast::flash($exception->userMessage, 'error');

            return redirect($back);
        }

        Audit::record('skatteverket.signed_in', null, [], $user->currentCompany(), $user);
        Toast::flash(__('Du är inloggad hos Skatteverket i ungefär en timme.'));

        return redirect($back);
    }

    /** Only a path in NONOX itself, never another site. */
    private static function safeBack(string $back): string
    {
        return str_starts_with($back, '/') && ! str_starts_with($back, '//') && ! str_contains($back, '\\')
            ? $back
            : route('vat.index', absolute: false);
    }
}
