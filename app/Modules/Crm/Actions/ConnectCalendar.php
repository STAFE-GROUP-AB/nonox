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
use App\Modules\Crm\Models\CalendarEvent;
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
 * user picks another. A private account is refused unless the company
 * allows private accounts. Reconnecting the same provider replaces the
 * tokens; reconnecting with another account starts over (new calendar, no
 * event map, the old grant revoked) and writes everything again. Another
 * provider needs a disconnect first. The first sync is queued.
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
        $account = $provider->account($tokens);

        // Organisation accounts only, unless the company has allowed private ones (ADR-049).
        if (! $account->organisation && ! CalendarRules::privateAccountsAllowed($company)) {
            try {
                $provider->revoke($tokens->refreshToken ?? $tokens->accessToken);
            } catch (\Throwable) {
                // Best effort: the refusal is shown whatever the provider answers.
            }

            throw ValidationException::withMessages(['calendar' => __('Företaget tillåter bara jobbkonton (Google Workspace eller Microsoft 365). Logga in med ditt jobbkonto.')]);
        }

        $calendars = $provider->calendars($tokens->accessToken);
        $primary = collect($calendars)->first(fn (RemoteCalendar $calendar): bool => $calendar->primary) ?? ($calendars[0] ?? null);
        // Another account than before: nothing of the old calendar applies to it.
        $otherAccount = $existing instanceof CalendarConnection && mb_strtolower((string) $existing->account_email) !== mb_strtolower((string) $account->email);
        $oldGrant = $otherAccount ? ($existing->refresh_token ?? $existing->access_token) : null;

        $connection = CurrentCompany::run($company, fn (): CalendarConnection => DB::transaction(function () use ($user, $company, $key, $tokens, $account, $primary, $existing, $otherAccount): CalendarConnection {
            $attributes = [
                'provider' => $key,
                'account_email' => $account->email,
                'organisation_account' => $account->organisation,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken ?? ($otherAccount ? null : $existing?->refresh_token),
                'token_expires_at' => $tokens->expiresAt,
                'status' => CalendarConnection::ACTIVE,
                'last_error' => null,
            ];

            if ($existing instanceof CalendarConnection) {
                if ($otherAccount) {
                    // The events NONOX wrote are in the old account's calendar; the new one starts empty.
                    CalendarEvent::query()->where('connection_id', $existing->id)->delete();
                    $attributes += ['calendar_id' => $primary?->id, 'calendar_name' => $primary?->name, 'sync_cursor' => null, 'cursor_started_at' => null];
                }

                $existing->forceFill($attributes)->save();
                Audit::record('crm.calendar_reconnected', $existing, ['provider' => $key->value, 'other_account' => $otherAccount], $company, $user);

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

        if ($oldGrant !== null) {
            // Best effort: the old account's grant is no longer used.
            try {
                $provider->revoke($oldGrant);
            } catch (\Throwable) {
                // The new connection works regardless; the user can also remove the old grant at the provider.
            }
        }

        SyncCalendarConnection::dispatch($company->id, $connection->id, $otherAccount)->afterCommit();

        return $connection;
    }
}
