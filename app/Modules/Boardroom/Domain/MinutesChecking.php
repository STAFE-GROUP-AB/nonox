<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use App\Modules\Boardroom\Models\Meeting;

/**
 * Who must check (justera) a set of minutes (rule sheet AB-R32, AB-R34):
 * general meeting minutes are checked by the chair and at least one adjuster
 * the meeting appointed, unless the chair and the minute-taker alone
 * represent all shares (ABL 7 kap. 48 §); board minutes by the chair and,
 * when the board has several members, a member the board appoints (8 kap.
 * 24 §).
 */
final class MinutesChecking
{
    /**
     * Whether at least one adjuster is needed.
     *
     * @param  array<int, int>  $sharesByHolder  from the register on the meeting day
     */
    public static function needsAdjuster(Meeting $meeting, array $sharesByHolder): bool
    {
        $attendees = (array) $meeting->attendees;

        if ($meeting->kind === MeetingKind::BoardMeeting) {
            $members = array_unique(array_map(
                fn (array $attendee): string => mb_strtolower(trim((string) $attendee['name'])),
                array_filter($attendees, fn (array $attendee): bool => $attendee['role'] === 'member'),
            ));

            return count($members) > 1;
        }

        $total = array_sum($sharesByHolder);

        if ($total === 0) {
            return true;
        }

        $officers = array_map(fn (?string $name): string => mb_strtolower(trim((string) $name)), [$meeting->chair_name, $meeting->minute_taker_name]);
        $holders = [];

        foreach ($attendees as $attendee) {
            $holder = $attendee['shareholder_id'] ?? null;

            if (is_int($holder) && in_array(mb_strtolower(trim((string) $attendee['name'])), $officers, true)) {
                $holders[$holder] = true;
            }
        }

        $represented = array_sum(array_map(fn (int $holder): int => $sharesByHolder[$holder] ?? 0, array_keys($holders)));

        return $represented < $total;
    }
}
