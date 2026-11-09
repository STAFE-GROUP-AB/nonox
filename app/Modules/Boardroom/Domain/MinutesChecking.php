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
 * 24 §). NONOX does not keep the registered board yet, so the board's size
 * is the number of attending ordinary members (role "member"), and the
 * second checker must be one of them and not the chair.
 */
final class MinutesChecking
{
    /**
     * Why the named checkers are not enough, or null when they are.
     *
     * @param  array<int, int>  $sharesByHolder
     * @param  list<string>  $adjusters
     */
    public static function problem(Meeting $meeting, array $sharesByHolder, string $chair, array $adjusters): ?string
    {
        if (! self::needsAdjuster($meeting, $sharesByHolder)) {
            return null;
        }

        $chairKey = self::key($chair);
        $others = array_values(array_filter($adjusters, fn (string $name): bool => self::key($name) !== $chairKey));

        if ($meeting->kind === MeetingKind::BoardMeeting) {
            $members = array_map(fn (array $attendee): string => self::key((string) $attendee['name']), array_filter((array) $meeting->attendees, fn (array $attendee): bool => $attendee['role'] === 'member'));
            $eligible = array_filter($others, fn (string $name): bool => in_array(self::key($name), $members, true));

            return $eligible === []
                ? (string) __('Styrelsen har flera ledamöter: protokollet justeras också av en närvarande ledamot som styrelsen utsett och som inte är ordförande (aktiebolagslagen 8 kap. 24 §).')
                : null;
        }

        return $others === []
            ? (string) __('Protokollet justeras av ordföranden och minst en justerare som stämman utsett, om inte ordföranden och protokollföraren ensamma företräder alla aktier (aktiebolagslagen 7 kap. 48 §).')
            : null;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

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
