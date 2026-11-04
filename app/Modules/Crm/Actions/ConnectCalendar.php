<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Calendar\CalendarProviderKey;
use App\Modules\Crm\Calendar\CalendarProviders;
use App\Modules\Crm\Calendar\CalendarRules;
use App\Modules\Crm\Calendar\Jobs\SyncCalendarConnection;
use App\Modules\Crm\Calendar\Providers\RemoteCalendar;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarConnection;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Finishes connecting the user's Google or Microsoft calendar in this
 * company (ADR-049), after the provider sent the user back with a code:
 * the code is exchanged (with the PKCE verifier) for tokens, which are kept
 * encrypted; the account's address is read so the screen can say which
 * account is connected; the primary writable calendar is chosen until the
 * user picks another. Reconnecting the same provider replaces the tokens;
 * another provider needs a disconnect first. The first sync is queued.
 */
final class ConnectCalendar
{
    public function __construct(private readonly CalendarProviders $providers) {}

    public function __invoke(User $user, Company $company, CalendarProviderKey $key, string $code, string $verifier): CalendarConnection
    {
        Gate::forUser($user)->authorize('viewAny', [Activity::class, $company]);
        $provider = $this->providers->for($key);
        abort_unless($provider->isConfigured() && CalendarRules::syncAllowed($company), 404);

        $existing = CurrentCompany::run($company, fn (): ?CalendarConnection => CalendarConnection::query()->where('user_id', $user->id)->first());

        if ($existing instanceof CalendarConnection && ($existing->provider !== $key || $existing->status === CalendarConnection::REVOKED)) {
            throw ValidationException::withMessages(['calendar' => __('Du har redan en kopplad kalender. Koppla bort den först.')]);
        }

        $tokens = $provider->exchangeCode($code, $verifier);
        $email = $provider->accountEmail($tokens->accessToken);
        $calendars = $provider->calendars($tokens->accessToken);
        $primary = collect($calendars)->first(fn (RemoteCalendar $calendar): bool => $calendar->primary) ?? ($calendars[0] ?? null);

        $connection = CurrentCompany::run($company, fn (): CalendarConnection => DB::transaction(function () use ($user, $company, $key, $tokens, $email, $primary, $existing): CalendarConnection {
            $attributes = [
                'provider' => $key,
                'account_email' => $email,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken ?? $existing?->refresh_token,
                'token_expires_at' => $tokens->expiresAt,
                'status' => CalendarConnection::ACTIVE,
                'last_error' => null,
            ];

            if ($existing instanceof CalendarConnection) {
                $existing->forceFill($attributes)->save();
                Audit::record('crm.calendar_reconnected', $existing, ['provider' => $key->value], $company, $user);

                return $existing;
            }

            $connection = CalendarConnection::create([
                ...$attributes,
                'user_id' => $user->id,
                'calendar_id' => $primary?->id,
                'calendar_name' => $primary?->name,
            ]);
            Audit::record('crm.calendar_connected', $connection, ['provider' => $key->value], $company, $user);

            return $connection;
        }));

        SyncCalendarConnection::dispatch($company->id, $connection->id)->afterCommit();

        return $connection;
    }
}
