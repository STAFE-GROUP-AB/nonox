<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use App\Modules\Boardroom\Models\ShareEvent;
use Carbon\CarbonImmutable;

/**
 * The tamper-evident chain of a company's share register (ADR-070 §2):
 * hash = sha256(previous_hash || canonical_json(entry)), the same
 * construction as the voucher chain (ADR-007). The holder's details are
 * hashed in clear text, so re-encrypting them with a new key does not break
 * the chain. Dates are hashed as dates, the entry time in whole seconds of
 * the application's time zone.
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

    /** The time as it reads back from the database: whole seconds in the application's time zone. */
    public static function storable(CarbonImmutable $time): CarbonImmutable
    {
        $zone = (string) config('app.timezone');

        return CarbonImmutable::createFromFormat(self::TIME_FORMAT, $time->setTimezone($zone)->format(self::TIME_FORMAT), $zone) ?: $time;
    }
}
