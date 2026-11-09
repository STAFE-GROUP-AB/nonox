<?php

declare(strict_types=1);

namespace App\Support\Status;

use App\Console\Commands\VerifyLedgerCommand;
use App\Models\StatusHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Throwable;

/**
 * When the jobs behind the public status page (ADR-078) last ran. Two
 * sources: the scheduler (a listener on the scheduled-task events, for the
 * commands in SCHEDULED) and `status:heartbeat`, which the operator's own
 * scripts call (backup, restore test). Only these keys are ever recorded,
 * so a status page shows what the instance can really know.
 *
 * A run that never started (skipped because the previous one still holds
 * the overlap mutex) leaves no exit code and is not recorded.
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

    /**
     * artisan command (with the options the schedule uses) => heartbeat key.
     * ledger:verify is matched in its scheduled `--all` form only.
     *
     * @var array<string, string>
     */
    public const array SCHEDULED = [
        'connect:webhooks' => self::SCHEDULER,
        'ledger:verify --all' => self::INTEGRITY,
        'peppol:sync' => self::PEPPOL,
        'bank:fetch' => self::BANK,
    ];

    /** @var list<string> the keys an operator script may report */
    public const array REPORTED = [self::BACKUP, self::RESTORE_TEST];

    /**
     * @param  ?bool  $completed  whether the run did its work (default: it succeeded); a check that found deviations completed
     */
    public static function record(string $key, bool $success, ?CarbonImmutable $at = null, ?int $exitCode = null, ?bool $completed = null): void
    {
        $at ??= CarbonImmutable::now();
        $completed ??= $success;
        $row = StatusHeartbeat::query()->find($key);
        $before = $row instanceof StatusHeartbeat ? self::healthy($row) : null;
        $row ??= new StatusHeartbeat(['key' => $key]);

        $row->last_run_at = $at;
        $row->last_exit_code = $exitCode ?? ($success ? 0 : 1);

        if ($success) {
            $row->last_success_at = $at;
        } else {
            $row->last_failure_at = $at;
        }

        if ($completed) {
            $row->last_completed_at = $at;
        }

        $row->save();

        // The page is cached for a minute; only a change of state is worth clearing it for.
        if ($before !== self::healthy($row)) {
            StatusSnapshot::forget();
        }
    }

    public static function healthy(StatusHeartbeat $row): bool
    {
        return $row->last_failure_at === null
            || ($row->last_success_at !== null && $row->last_failure_at->lessThan($row->last_success_at));
    }

    public static function finished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        $key = self::keyFor((string) $task->command);

        if ($key === null || $task->skippedBecauseOverlapping || $task->exitCode === null) {
            return;
        }

        $code = (int) $task->exitCode;
        $completed = $code === 0 || ($key === self::INTEGRITY && $code === VerifyLedgerCommand::EXIT_DEVIATIONS);

        self::safely(fn () => self::record($key, $code === 0, null, $code, $completed));
    }

    public static function failed(ScheduledTaskFailed $event): void
    {
        $task = $event->task;
        $key = self::keyFor((string) $task->command);

        if ($key === null || $task->skippedBecauseOverlapping) {
            return;
        }

        // Findings were recorded when the task finished; this event only repeats them as an exception.
        if ($key === self::INTEGRITY && $task->exitCode === VerifyLedgerCommand::EXIT_DEVIATIONS) {
            return;
        }

        self::safely(fn () => self::record($key, false, null, $task->exitCode === null ? null : (int) $task->exitCode, false));
    }

    public static function keyFor(string $command): ?string
    {
        foreach (self::SCHEDULED as $name => $key) {
            if (preg_match('/(^|artisan\'?\s+)'.preg_quote($name, '/').'(?=\s*$|\s*\'?$)/', $command) === 1) {
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
