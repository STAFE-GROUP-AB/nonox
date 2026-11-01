<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Domain\EntryFlag;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Models\Device;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Modules\StaffLedger\StaffLedgerSettings;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Receives check-ins and check-outs from a kiosk, live or from its offline
 * queue (ADR-022). Each event carries the device's time, a per-device
 * sequence number and the device's own hash chain. The server:
 *
 * - stores the event time as the device gave it and its own received time;
 * - is idempotent on (device, sequence): a resent event is acknowledged, not
 *   stored twice;
 * - accepts late events in sequence order and never drops or rewrites one,
 *   but flags clock skew, lateness, skipped sequence numbers and a broken
 *   device chain on the entry;
 * - rejects only what it cannot store: an unknown person or a malformed event.
 */
final class RecordKioskEvents
{
    /** An event this much older than its sending counts as sent afterwards. */
    public const int LATE_AFTER_SECONDS = 900;

    /** At most this many offline control visit openings are written per sync. */
    public const int MAX_OFFLINE_OPENINGS_PER_SYNC = 5;

    public function __construct(private readonly AppendEntry $append, private readonly ModuleManager $modules) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $logs  things the kiosk did offline that belong in the audit log
     * @return array{accepted: list<int>, duplicates: list<int>, rejected: list<array{sequence: int, reason: string}>, last_sequence: int}
     */
    public function __invoke(Device $device, array $events, ?string $sentAt, array $logs = []): array
    {
        $this->modules->ensureEnabled(StaffLedgerModule::KEY);

        $now = CarbonImmutable::now();
        $deviceNow = self::parseTime($sentAt) ?? $now;
        $skew = (int) round($deviceNow->diffInSeconds($now, false));
        $skewLimit = StaffLedgerSettings::int($this->modules->settings(StaffLedgerModule::KEY, $device->company()->firstOrFail()), 'clock_skew_minutes') * 60;
        $result = ['accepted' => [], 'duplicates' => [], 'rejected' => [], 'last_sequence' => 0];

        usort($events, fn (array $a, array $b): int => (int) ($a['sequence'] ?? 0) <=> (int) ($b['sequence'] ?? 0));

        foreach ($events as $event) {
            $sequence = (int) ($event['sequence'] ?? 0);

            try {
                $status = DB::transaction(fn (): array => $this->record($device, $event, $sequence, $deviceNow, $skew, $skewLimit));
            } catch (Throwable $exception) {
                report($exception);
                $status = ['rejected', 'error'];
            }

            match ($status[0]) {
                'accepted' => $result['accepted'][] = $sequence,
                'duplicate' => $result['duplicates'][] = $sequence,
                default => $result['rejected'][] = ['sequence' => $sequence, 'reason' => $status[1] ?? 'invalid'],
            };
        }

        $this->recordOfflineOpenings($device, $logs);

        $device->forceFill(['last_seen_at' => $now])->save();
        $result['last_sequence'] = $device->last_sequence;

        return $result;
    }

    /**
     * Control visit openings the kiosk reports from offline use. The server
     * cannot verify them (the PIN was checked on the device), so each is
     * written as reported by the device, and only when it names an active
     * manager of the device's company who has a PIN. Identical reports are
     * written once, and at most MAX_OFFLINE_OPENINGS_PER_SYNC per sync, so a
     * device token can neither flood the audit log nor name anybody it likes.
     *
     * @param  list<array<string, mixed>>  $logs
     */
    private function recordOfflineOpenings(Device $device, array $logs): void
    {
        $reported = [];

        foreach ($logs as $log) {
            if (($log['type'] ?? null) !== 'control_visit_opened' || ! is_numeric($log['person_id'] ?? null)) {
                continue;
            }

            $personId = (int) $log['person_id'];
            $at = is_string($log['at'] ?? null) ? mb_substr($log['at'], 0, 40) : null;
            $reported["{$personId}|{$at}"] = ['person_id' => $personId, 'at' => $at];
        }

        if ($reported === []) {
            return;
        }

        $managers = Person::query()
            ->where('company_id', $device->company_id)
            ->whereIn('id', array_values(array_unique(array_column($reported, 'person_id'))))
            ->where('is_manager', true)
            ->whereNull('archived_at')
            ->whereNotNull('pin_verifier')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $written = 0;

        foreach ($reported as $opening) {
            if ($written >= self::MAX_OFFLINE_OPENINGS_PER_SYNC) {
                return;
            }

            if (! in_array($opening['person_id'], $managers, true)) {
                continue;
            }

            Audit::record('staff_ledger.control_visit_opened', $device, [
                'where' => 'kiosk_offline',
                'verified' => false,
                'reported_by' => 'device',
                'manager_person_id' => $opening['person_id'],
                'at' => $opening['at'],
            ], $device->company_id);
            $written++;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1?: string}
     */
    private function record(Device $device, array $event, int $sequence, CarbonImmutable $deviceNow, int $skew, int $skewLimit): array
    {
        /** @var Device $locked */
        $locked = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();

        $uuid = is_string($event['uuid'] ?? null) && Str::isUuid($event['uuid']) ? strtolower($event['uuid']) : null;
        $direction = Direction::tryFrom((string) ($event['direction'] ?? ''));
        $method = CheckInMethod::tryFrom((string) ($event['method'] ?? ''));
        $occurredAt = self::parseTime(is_string($event['occurred_at'] ?? null) ? $event['occurred_at'] : null);

        if ($sequence < 1 || $uuid === null || $direction === null || ! in_array($method, [CheckInMethod::KioskPin, CheckInMethod::KioskQr], true) || $occurredAt === null) {
            return ['rejected', 'invalid'];
        }

        if (Entry::query()->where(fn ($query) => $query->where('device_id', $locked->id)->where('device_sequence', $sequence))->orWhere('uuid', $uuid)->exists()) {
            return ['duplicate'];
        }

        $person = Person::query()->find((int) ($event['person_id'] ?? 0));

        if (! $person instanceof Person) {
            return ['rejected', 'unknown_person'];
        }

        $clientHash = is_string($event['client_hash'] ?? null) ? $event['client_hash'] : null;
        $previousClientHash = is_string($event['previous_client_hash'] ?? null) ? $event['previous_client_hash'] : null;
        $flags = [];

        if (abs($skew) > $skewLimit) {
            $flags[] = EntryFlag::ClockSkew->value;
        }

        if ($occurredAt->diffInSeconds($deviceNow, false) > self::LATE_AFTER_SECONDS) {
            $flags[] = EntryFlag::Late->value;
        }

        if ($sequence > $locked->last_sequence + 1) {
            $flags[] = EntryFlag::SequenceGap->value;
        }

        $expected = $clientHash === null ? null : hash('sha256', ($previousClientHash ?? '').self::clientCanonical($event, $locked->location_id, $person->id, $uuid, $sequence));
        $chainContinues = $sequence !== $locked->last_sequence + 1 || $previousClientHash === $locked->last_client_hash;

        if ($expected === null || ! hash_equals($expected, (string) $clientHash) || ! $chainContinues) {
            $flags[] = EntryFlag::ClientChainBreak->value;
        }

        $location = Location::query()->findOrFail($locked->location_id);

        ($this->append)($location, $person, [
            'uuid' => $uuid,
            'type' => $direction === Direction::In ? EntryType::CheckIn : EntryType::CheckOut,
            'method' => $method,
            'occurred_at' => $occurredAt,
            'device_id' => $locked->id,
            'device_sequence' => $sequence,
            'client_hash' => $clientHash,
            'clock_skew_seconds' => $skew,
            'flags' => $flags,
        ]);

        if ($sequence > $locked->last_sequence) {
            $locked->forceFill(['last_sequence' => $sequence, 'last_client_hash' => $clientHash])->save();
            $device->setRawAttributes($locked->getAttributes(), true);
        }

        return ['accepted'];
    }

    /**
     * The device's canonical form of an event, the same as kiosk-core.js:
     * the fields in key order, as JSON.
     *
     * @param  array<string, mixed>  $event
     */
    public static function clientCanonical(array $event, int $locationId, int $personId, string $uuid, int $sequence): string
    {
        return (string) json_encode([
            'direction' => (string) ($event['direction'] ?? ''),
            'location_id' => $locationId,
            'method' => (string) ($event['method'] ?? ''),
            'occurred_at' => (string) ($event['occurred_at'] ?? ''),
            'person_id' => $personId,
            'sequence' => $sequence,
            'uuid' => $uuid,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function parseTime(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
