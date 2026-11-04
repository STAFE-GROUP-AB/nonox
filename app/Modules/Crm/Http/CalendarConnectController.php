<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\ConnectCalendar;
use App\Modules\Crm\Calendar\CalendarProviderKey;
use App\Modules\Crm\Calendar\CalendarProviders;
use App\Modules\Crm\Calendar\CalendarRules;
use App\Modules\Crm\Calendar\Providers\CalendarProviderException;
use App\Modules\Crm\Models\Activity;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The OAuth round trip to Google or Microsoft (ADR-049). "Koppla" sends the
 * user to the provider with a random state and a PKCE challenge, both kept
 * in the session with the user and company; the provider sends the user
 * back to /kunder/kalender/koppla/{provider}/svar, where the state must
 * match before the code is exchanged. An unconfigured provider answers 404.
 */
final class CalendarConnectController
{
    public const string SESSION = 'crm_calendar_oauth';

    public function redirect(Request $request, string $provider, CalendarProviders $providers): RedirectResponse
    {
        [$user, $company, $key] = $this->context($request, $provider, $providers);

        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put(self::SESSION, [
            'state' => $state,
            'verifier' => $verifier,
            'provider' => $key->value,
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($providers->for($key)->authorizationUrl($state, $challenge));
    }

    public function callback(Request $request, string $provider, CalendarProviders $providers, ConnectCalendar $connect): RedirectResponse
    {
        [$user, $company, $key] = $this->context($request, $provider, $providers);
        $pending = $request->session()->pull(self::SESSION);
        $back = redirect()->route('crm.calendar');

        $valid = is_array($pending)
            && is_string($pending['state'] ?? null)
            && hash_equals($pending['state'], (string) $request->query('state'))
            && ($pending['provider'] ?? null) === $key->value
            && ($pending['user_id'] ?? null) === $user->id
            && ($pending['company_id'] ?? null) === $company->id;

        if (! $valid) {
            Toast::flash(__('Kopplingen kunde inte bekräftas. Försök igen.'), 'error');

            return $back;
        }

        if ($request->query('error') !== null || ! is_string($request->query('code'))) {
            Toast::flash(__('Kalendern kopplades inte. Du avbröt eller nekade åtkomst.'), 'info');

            return $back;
        }

        try {
            $connect($user, $company, $key, (string) $request->query('code'), (string) $pending['verifier']);
        } catch (ValidationException $exception) {
            Toast::flash((string) collect($exception->errors())->flatten()->first(), 'error');

            return $back;
        } catch (CalendarProviderException) {
            Toast::flash(__(':provider svarade inte som väntat. Försök igen om en stund.', ['provider' => $key->label()]), 'error');

            return $back;
        }

        Toast::flash(__(':provider är kopplad. Dina planerade aktiviteter läggs in inom några minuter.', ['provider' => $key->label()]));

        return $back;
    }

    /** @return array{0: User, 1: Company, 2: CalendarProviderKey} */
    private function context(Request $request, string $provider, CalendarProviders $providers): array
    {
        $key = CalendarProviderKey::fromSlug($provider);
        abort_unless($key instanceof CalendarProviderKey && $providers->for($key)->isConfigured(), 404);

        /** @var User $user */
        $user = $request->user();
        $company = $user->currentCompany();
        abort_unless($company instanceof Company && $user->can('viewAny', [Activity::class, $company]), 403);
        // The company decides whether CRM data may be copied to Google or Microsoft.
        abort_unless(CalendarRules::syncAllowed($company), 404);

        return [$user, $company, $key];
    }
}
