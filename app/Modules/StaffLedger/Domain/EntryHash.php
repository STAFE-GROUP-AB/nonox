<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

use App\Modules\StaffLedger\Models\Entry;
use Carbon\CarbonImmutable;

/**
 * The tamper-evident chain of a location's staff ledger (ADR-022):
 * hash = sha256(previous_hash || canonical_json(entry)), the same
 * construction as the voucher chain (LedgerHash). The personal number is
 * hashed in clear text, so re-encrypting it with a new key does not break
 * the chain. Times are hashed as stored: whole seconds, local time.
 *
 * The issuing countries of a foreign identity number and a foreign
 * employer number (added 2026-11-07) are part of the payload only when they
 * are set, so every entry written before them hashes exactly as it did.
 */
final class EntryHash
{
    public const string TIME_FORMAT = 'Y-m-d H:i:s';

    public static function of(Entry $entry, ?string $previousHash): string
    {
        return hash('sha256', ($previousHash ?? '').self::canonicalJson($entry));
    }

    public static function canonicalJson(Entry $entry): string
    {
        $payload = [
            'client_hash' => $entry->client_hash,
            'clock_skew_seconds' => $entry->clock_skew_seconds,
            'company_id' => $entry->company_id,
            'correction' => $entry->correction?->value,
            'corrects_entry_id' => $entry->corrects_entry_id,
            'device_id' => $entry->device_id,
            'device_sequence' => $entry->device_sequence,
            'direction' => $entry->direction?->value,
            'employer_name' => $entry->employer_name,
            'employer_org_number' => $entry->employer_org_number,
            'flags' => $entry->flags,
            'geo_distance_meters' => $entry->geo_distance_meters,
            'id' => $entry->id,
            'location_id' => $entry->location_id,
            'method' => $entry->method->value,
            'occurred_at' => $entry->occurred_at->format(self::TIME_FORMAT),
            'person_id' => $entry->person_id,
            'person_name' => $entry->person_name,
            'personal_number' => $entry->personal_number,
            'reason' => $entry->reason,
            'received_at' => $entry->received_at->format(self::TIME_FORMAT),
            'type' => $entry->type->value,
            'user_id' => $entry->user_id,
            'uuid' => $entry->uuid,
        ];

        foreach (['identity_country' => $entry->identity_country, 'employer_country' => $entry->employer_country] as $key => $country) {
            if ($country !== null) {
                $payload[$key] = $country;
            }
        }

        ksort($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The time as it will read back from the database (whole seconds in the
     * application's time zone), so the hash computed before saving matches
     * the one recomputed later, also in the hour that repeats in October.
     */
    public static function storable(CarbonImmutable $time): CarbonImmutable
    {
        $zone = (string) config('app.timezone');

        return CarbonImmutable::createFromFormat(self::TIME_FORMAT, $time->setTimezone($zone)->format(self::TIME_FORMAT), $zone) ?: $time;
    }
}
