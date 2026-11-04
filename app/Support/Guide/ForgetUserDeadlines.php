<?php

declare(strict_types=1);

namespace App\Support\Guide;

use App\Actions\Guide\RevokeDeadlineFeed;
use App\Events\UserAccountDeleting;
use App\Models\Company;
use App\Models\DeadlineCalendarFeed;
use App\Models\DeadlineReminder;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Events\TeamMemberRemoved;

/**
 * A user's Mina datum calendar link ends with the user's place in the
 * company (ADR-051, stage 2); the feed checks membership on every request
 * anyway, this removes what is left. Deleting one's account deletes every
 * link and the record of which reminders were sent.
 */
final class ForgetUserDeadlines
{
    public function memberRemoved(TeamMemberRemoved $event): void
    {
        if (! $event->team instanceof Company || ! $event->user instanceof User) {
            return;
        }

        $by = Auth::user();
        app(RevokeDeadlineFeed::class)($event->user, $event->team, $by instanceof User ? $by : null);
    }

    public function accountDeleting(UserAccountDeleting $event): void
    {
        // The user's rows in every company: the account is going, not one membership.
        CompanyScope::bypass(function () use ($event): void {
            DeadlineCalendarFeed::query()->where('user_id', $event->userId)->delete();
            DeadlineReminder::query()->where('user_id', $event->userId)->delete();
        });
    }
}
