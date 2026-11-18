<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The deadline and reminder rules of an envelope (ADR-046 amendment
 * 2026-11-18, gap 9, package S9):
 *
 * - The last day to sign is 3–90 days from today (default 30, owner decision
 *   14), at the end of that day in Stockholm time, and never after a quote's
 *   last valid day. When the quote's last valid day is nearer than three
 *   days, it is the last day that can be chosen.
 * - A sender may switch on reminders every 1, 2, 3 or 7 days (default 3,
 *   owner decision 13); without an interval there is one reminder two days
 *   before the deadline. At most ten reminders reach one signer by rule,
 *   only between 08:00 and 18:00 Stockholm time and never at or after the
 *   deadline. A person may remind a signer by hand, at most once an hour.
 *
 * Times are compared as instants; the Stockholm clock is used only to
 * decide the business hours and where a day ends.
 */
final class SigningDeadlines
{
    public const int MIN_DAYS = 3;

    public const int MAX_DAYS = 90;

    public const int DEFAULT_DAYS = 30;

    /** @var list<int> */
    public const array INTERVALS = [1, 2, 3, 7];

    public const int DEFAULT_INTERVAL = 3;

    public const int MAX_REMINDERS = 10;

    /** The single reminder of an envelope without an interval goes this many days before the deadline. */
    public const int SINGLE_REMINDER_DAYS_BEFORE = 2;

    /** A signer is not reminded by hand more than once in this many minutes. */
    public const int MANUAL_COOLDOWN_MINUTES = 60;

    public const int HOUR_FROM = 8;

    public const int HOUR_TO = 18;

    public const string ZONE = 'Europe/Stockholm';

    /**
     * The end of the chosen last day, or the default one.
     *
     * @throws ValidationException
     */
    public static function resolve(Signable $document, mixed $given, CarbonImmutable $today, string $field = 'expires_on'): CarbonImmutable
    {
        $until = $document->signingValidUntil();
        $latest = $today->addDays(self::MAX_DAYS);
        $untilDay = $until === null ? null : CarbonImmutable::parse($until->toDateString(), self::ZONE)->startOfDay();

        if (is_string($given) && $given !== '') {
            $day = CarbonImmutable::parse($given, self::ZONE)->startOfDay();
            // A quote that is valid for fewer than three more days can still be sent: its own last day is then the earliest allowed.
            $earliest = $today->addDays(self::MIN_DAYS);
            $earliest = $untilDay !== null && $untilDay->lessThan($earliest) ? $today->max($untilDay) : $earliest;

            if ($day->lessThan($earliest) || $day->greaterThan($latest)) {
                throw ValidationException::withMessages([$field => __('Sista dag att signera ska vara om minst :min dagar och inom :max dagar.', ['min' => self::MIN_DAYS, 'max' => self::MAX_DAYS])]);
            }

            // A quote cannot be signed after its last valid day.
            if ($untilDay !== null && $day->greaterThan($untilDay)) {
                throw ValidationException::withMessages([$field => __('Sista dag att signera kan inte vara efter offertens giltighetsdag (:date).', ['date' => $untilDay->toDateString()])]);
            }

            return $day->endOfDay();
        }

        if ($untilDay !== null && $untilDay->greaterThanOrEqualTo($today)) {
            return ($untilDay->greaterThan($latest) ? $latest : $untilDay)->endOfDay();
        }

        return $today->addDays(self::DEFAULT_DAYS)->endOfDay();
    }

    /**
     * The reminder interval a sender chose: 1, 2, 3 or 7, or null for the
     * single reminder before the deadline.
     *
     * @throws ValidationException
     */
    public static function interval(mixed $given, string $field = 'reminder_days'): ?int
    {
        if ($given === null || $given === '' || $given === 'single') {
            return null;
        }

        if (! is_numeric($given) || ! in_array((int) $given, self::INTERVALS, true)) {
            throw ValidationException::withMessages([$field => __('Välj att påminna var 1, 2, 3 eller 7:e dag, eller en gång före sista dag.')]);
        }

        return (int) $given;
    }

    /** Whether the clock is within the hours reminders are sent, in Stockholm time. */
    public static function inBusinessHours(CarbonImmutable $now): bool
    {
        $hour = (int) $now->setTimezone(self::ZONE)->format('G');

        return $hour >= self::HOUR_FROM && $hour < self::HOUR_TO;
    }

    /**
     * Whether a reminder is due by rule for a signer whose link has been
     * open since $openSince. The signer's step must be open and pending
     * (the caller checks); this decides the time and the count.
     */
    public static function reminderDue(SigningEnvelope $envelope, SigningRequest $signer, CarbonImmutable $openSince, CarbonImmutable $now): bool
    {
        if (! $envelope->auto_reminders || $envelope->deadline_at === null || ! $now->lessThan($envelope->deadline_at)) {
            return false;
        }

        if ($signer->reminder_count >= self::MAX_REMINDERS || ! self::inBusinessHours($now)) {
            return false;
        }

        if ($envelope->reminder_interval_days === null) {
            $threshold = $envelope->deadline_at->subDays(self::SINGLE_REMINDER_DAYS_BEFORE);

            return $now->greaterThanOrEqualTo($threshold) && ($signer->last_reminded_at === null || $signer->last_reminded_at->lessThan($threshold));
        }

        $base = $signer->last_reminded_at ?? $openSince;

        return $now->greaterThanOrEqualTo($base->addDays($envelope->reminder_interval_days));
    }

    /** When the signer may next be reminded by hand, or null when now is fine. */
    public static function manualAvailableAt(SigningRequest $signer, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($signer->last_reminded_at === null) {
            return null;
        }

        $at = $signer->last_reminded_at->addMinutes(self::MANUAL_COOLDOWN_MINUTES);

        return $at->greaterThan($now) ? $at : null;
    }
}
