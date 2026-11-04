<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use App\Events\UserAccountDeleting;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\RevokeCalendarFeed;
use App\Modules\Crm\Calendar\Jobs\DisconnectCalendar;
use App\Modules\Crm\Models\CalendarConnection;
use App\Modules\Crm\Models\CalendarFeed;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Events\TeamMemberRemoved;

/**
 * A user's calendar links and connected calendars end with the user's place
 * in the company (ADR-049). The feed checks membership on every request
 * anyway; this removes what is left.
 *
 * - Leaving or being removed from a company: the link in that company is
 *   revoked and the connection disconnected (a job revokes the grant and
 *   removes NONOX's events).
 * - Deleting one's account: every link is deleted, and every connection's
 *   tokens are deleted in the same transaction; the grant is revoked at the
 *   provider after the commit, best effort, without removing events (the
 *   user's calendar is theirs to keep).
 */
final class ForgetUserCalendars
{
    public function memberRemoved(TeamMemberRemoved $event): void
    {
        if (! $event->team instanceof Company || ! $event->user instanceof User) {
            return;
        }

        $by = Auth::user();
        app(RevokeCalendarFeed::class)($event->user, $event->team, $by instanceof User ? $by : null);

        // Who left is not who acted, so the connection is found without the current company's scope.
        $connection = CompanyScope::bypass(fn (): ?CalendarConnection => CalendarConnection::query()
            ->where('company_id', $event->team->id)
            ->where('user_id', $event->user->id)
            ->first());

        if ($connection instanceof CalendarConnection) {
            CompanyScope::bypass(fn () => $connection->forceFill(['status' => CalendarConnection::REVOKED])->save());
            DisconnectCalendar::dispatch($event->team->id, $connection->id);
        }
    }

    public function accountDeleting(UserAccountDeleting $event): void
    {
        // The user's rows in every company: the account is going, not one membership.
        CompanyScope::bypass(function () use ($event): void {
            CalendarFeed::query()->where('user_id', $event->userId)->delete();
            $connections = CalendarConnection::query()->where('user_id', $event->userId)->get();

            foreach ($connections as $connection) {
                $provider = app(CalendarProviders::class)->for($connection->provider);
                $token = $connection->refresh_token ?? $connection->access_token;
                $connection->delete();

                DB::afterCommit(function () use ($provider, $token): void {
                    if (! $provider->isConfigured()) {
                        return;
                    }

                    try {
                        $provider->revoke($token);
                    } catch (\Throwable) {
                        // Best effort: the tokens are already deleted here.
                    }
                });
            }
        });
    }
}
