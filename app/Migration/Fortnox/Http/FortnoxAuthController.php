<?php

declare(strict_types=1);

namespace App\Migration\Fortnox\Http;

use App\Migration\Fortnox\FortnoxMove;
use App\Migration\Fortnox\FortnoxOAuth;
use App\Models\Company;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sends the user to Fortnox to authorise NONOX, and receives the answer.
 * The state and the PKCE verifier live in the session, so the answer can
 * only finish a flow this browser started, for the company it started for.
 */
class FortnoxAuthController
{
    private const string SESSION = 'fortnox.oauth';

    public function redirect(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        Gate::authorize('importFortnox', $company);

        if (! FortnoxOAuth::isConfigured()) {
            Toast::flash(__('Hämtning från Fortnox är inte inställd på den här installationen.'), 'error');

            return redirect()->route('fortnox.move');
        }

        $state = Str::random(40);
        $verifier = Str::random(96);
        $request->session()->put(self::SESSION, ['state' => $state, 'verifier' => $verifier, 'company_id' => $company->id]);

        return redirect()->away(FortnoxOAuth::authorizeUrl($state, $verifier, route('fortnox.callback')));
    }

    public function callback(Request $request, FortnoxMove $move): RedirectResponse
    {
        $company = $this->company($request);
        Gate::authorize('importFortnox', $company);

        /** @var array{state?: string, verifier?: string, company_id?: int} $flow */
        $flow = (array) $request->session()->pull(self::SESSION, []);

        if (($flow['state'] ?? null) === null || ! hash_equals((string) $flow['state'], (string) $request->query('state')) || ($flow['company_id'] ?? null) !== $company->id) {
            Toast::flash(__('Anslutningen till Fortnox gick inte att slutföra. Försök igen.'), 'error');

            return redirect()->route('fortnox.move');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            Toast::flash(__('Fortnox gav inte tillgång. Ingenting har hämtats.'), 'error');

            return redirect()->route('fortnox.move');
        }

        try {
            $token = FortnoxOAuth::exchange((string) $request->query('code'), (string) ($flow['verifier'] ?? ''), route('fortnox.callback'));
            /** @var User $user */
            $user = $request->user();
            $move->connect($user, $company, $token);
        } catch (ValidationException $exception) {
            Toast::flash((string) collect($exception->errors())->flatten()->first(), 'error');

            return redirect()->route('fortnox.move');
        } catch (RuntimeException $exception) {
            report($exception);
            Toast::flash(__('Fortnox svarade inte som väntat. Försök igen om en stund.'), 'error');

            return redirect()->route('fortnox.move');
        }

        Toast::flash(__('NONOX är anslutet till Fortnox. Nu kan du hämta bokföringen.'));

        return redirect()->route('fortnox.move');
    }

    private function company(Request $request): Company
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company */
        return $user->currentCompany();
    }
}
