<?php

declare(strict_types=1);

namespace App\Support\Status;

use App\Models\StatusHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * When the jobs behind the public status page (ADR-078) last ran. Two
 * sources: the scheduler (a listener on the scheduled-task events, for the
 * commands in SCHEDULED) and `status:heartbeat`, which the operator's own
 * scripts call (backup, restore test). Only these keys are ever recorded,
 * so a status page shows what the instance can really know.
 */
final class Heartbeats
{
    /** Scheduler heartbeat: the job that runs every minute, so a stopped scheduler is seen. */
    public const string SCHEDULER = 'scheduler';

    public const string INTEGRITY = 'integrity';

    public const string PEPPOL = 'peppol';

    public const string BANK = 'bank';

    public const string BACKUP = 'backup';

    public const string RESTORE_TEST = 'restore-test';

    /** @var array<string, string> artisan command => heartbeat key */
    public const array SCHEDULED = [
        'connect:webhooks' => self::SCHEDULER,
        'ledger:verify' => self::INTEGRITY,
        'peppol:sync' => self::PEPPOL,
        'bank:fetch' => self::BANK,
    ];

    /** @var list<string> the keys an operator script may report */
    public const array REPORTED = [self::BACKUP, self::RESTORE_TEST];

    public static function record(string $key, bool $success, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();
        $row = StatusHeartbeat::query()->find($key) ?? new StatusHeartbeat(['key' => $key]);

        $row->last_run_at = $at;

        if ($success) {
            $row->last_success_at = $at;
        } else {
            $row->last_failure_at = $at;
        }

        $row->save();
        Cache::forget(StatusSnapshot::CACHE_KEY);
    }

    public static function finished(ScheduledTaskFinished $event): void
    {
        $key = self::keyFor((string) $event->task->command);

        if ($key !== null) {
            self::safely(fn () => self::record($key, (int) $event->task->exitCode === 0));
        }
    }

    public static function failed(ScheduledTaskFailed $event): void
    {
        $key = self::keyFor((string) $event->task->command);

        if ($key !== null) {
            self::safely(fn () => self::record($key, false));
        }
    }

    public static function keyFor(string $command): ?string
    {
        foreach (self::SCHEDULED as $name => $key) {
            if (preg_match('/(^|artisan\'?\s+)'.preg_quote($name, '/').'(?=\s|\'|$)/', $command) === 1) {
                return $key;
            }
        }

        return null;
    }

    /** A status record must never stop the job it describes (the table may not exist while migrating). */
    private static function safely(callable $record): void
    {
        try {
            $record();
        } catch (Throwable) {
            // Nothing to do: the next run records it.
        }
    }
}
