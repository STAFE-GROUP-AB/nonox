<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use App\Modules\Boardroom\Models\ShareEvent;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The tamper-evident chain of a company's share register (ADR-070 §2):
 * hash = sha256(previous_hash || canonical_json(entry)), the same
 * construction as the voucher chain (ADR-007). The holder's name and address
 * are hashed as they are, so re-encrypting them with a new APP_KEY does not
 * break the chain. A personal number or foreign id number is never in the
 * payload in clear text: it is replaced by HMAC-SHA256 with the dedicated,
 * stable key nonox.register_hash_key (NONOX_REGISTER_HASH_KEY), so the
 * payload can be written to the archive package and a guessed personal
 * number cannot be checked against a published hash. Dates are hashed as
 * dates, the entry time in whole seconds of the application's time zone.
 */
final class EventHash
{
    public const string TIME_FORMAT = 'Y-m-d H:i:s';

    public static function of(ShareEvent $event, ?string $previousHash): string
    {
        return hash('sha256', ($previousHash ?? '').self::canonicalJson($event));
    }

    public static function canonicalJson(ShareEvent $event): string
    {
        $snapshot = $event->holder_snapshot;

        if (is_array($snapshot)) {
            foreach (['personal_number', 'other_id'] as $field) {
                if (is_string($snapshot[$field] ?? null) && $snapshot[$field] !== '') {
                    $snapshot[$field] = self::pseudonym($snapshot[$field]);
                }
            }

            ksort($snapshot);
        }

        $payload = [
            'capital_change_ore' => $event->capital_change_ore,
            'certificate_issued' => $event->certificate_issued,
            'company_id' => $event->company_id,
            'consideration_ore' => $event->consideration_ore,
            'decided_on' => $event->decided_on?->toDateString(),
            'document_reference' => $event->document_reference,
            'effective_date' => $event->effective_date->toDateString(),
            'from_holder_id' => $event->from_holder_id,
            'holder_snapshot' => $snapshot,
            'id' => $event->id,
            'kind' => $event->kind->value,
            'note' => $event->note,
            'ratio' => $event->ratio,
            'reason' => $event->reason,
            'recorded_at' => $event->recorded_at->format(self::TIME_FORMAT),
            'refers_to_event_id' => $event->refers_to_event_id,
            'registered_on' => $event->registered_on?->toDateString(),
            'sequence' => $event->sequence,
            'share_class_id' => $event->share_class_id,
            'share_from' => $event->share_from,
            'share_to' => $event->share_to,
            'to_holder_id' => $event->to_holder_id,
            'user_id' => $event->user_id,
            'uuid' => $event->uuid,
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** "hmac:" and HMAC-SHA256 of an id number with the register's own key. */
    public static function pseudonym(string $value): string
    {
        return 'hmac:'.hash_hmac('sha256', $value, self::key());
    }

    public static function hasKey(): bool
    {
        $key = config('nonox.register_hash_key');

        return is_string($key) && $key !== '';
    }

    private static function key(): string
    {
        $key = config('nonox.register_hash_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('NONOX_REGISTER_HASH_KEY is not set. Run php artisan share-register:hash-key --write once, and never change it.');
        }

        return $key;
    }

    /** The time as it reads back from the database: whole seconds in the application's time zone. */
    public static function storable(CarbonImmutable $time): CarbonImmutable
    {
        $zone = (string) config('app.timezone');

        return CarbonImmutable::createFromFormat(self::TIME_FORMAT, $time->setTimezone($zone)->format(self::TIME_FORMAT), $zone) ?: $time;
    }
}
