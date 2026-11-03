<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Hq\SyncCompanyToOperator;
use App\Actions\Hq\SyncWaitlistEntryToOperator;
use App\Models\Company;
use App\Models\OperatorLink;
use App\Models\WaitlistEntry;
use App\Support\Instance\InstanceSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Copies one company (as a customer) or one queue applicant (as a CRM
 * prospect) to the operator's registers (ADR-044). Queued after the commit
 * with ids only; the target company is read from the settings when the job
 * runs, and every write into it goes through CurrentCompany::run inside the
 * Actions.
 *
 * A failure never reaches the user whose action queued the job: it is
 * written to the link (exception class and, for a validation error, the
 * message, never the data), reported to the log, and listed in /hq
 * (Inställningar → Synk misslyckades). The backfill command or "Försök
 * igen" runs it again.
 */
final class SyncToOperator implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string COMPANY = 'company';

    public const string WAITLIST = 'waitlist';

    public function __construct(public string $kind, public int $id) {}

    /** @return ?string the outcome, or null when nothing was to be done or it failed */
    public function handle(SyncCompanyToOperator $syncCompany, SyncWaitlistEntryToOperator $syncEntry): ?string
    {
        $settings = InstanceSettings::current();
        $settings->forget();
        $targetId = $settings->operatorCompanyId();
        $target = $targetId === null ? null : Company::query()->find($targetId);

        if ($target === null) {
            return null;
        }

        try {
            if ($this->kind === self::COMPANY) {
                $source = Company::query()->find($this->id);

                return $source === null ? null : $syncCompany($source, $target);
            }

            $entry = WaitlistEntry::query()->find($this->id);

            return $entry === null ? null : $syncEntry($entry, $target);
        } catch (Throwable $exception) {
            self::fail($this->kind, $this->id, $target->id, $exception);

            return null;
        }
    }

    /** Records the failure on the link, without personal data. */
    public static function fail(string $kind, int $id, int $targetId, Throwable $exception): void
    {
        report($exception);

        $column = $kind === self::COMPANY ? 'source_company_id' : 'waitlist_entry_id';
        $error = class_basename($exception);

        if ($exception instanceof ValidationException) {
            $error .= ': '.$exception->validator->errors()->first();
        }

        $link = OperatorLink::query()->firstOrNew(['target_company_id' => $targetId, $column => $id]);

        // A link that already worked keeps its status: the failure is the latest attempt.
        if (! $link->exists) {
            $link->status = OperatorLink::FAILED;
        }

        $link->forceFill(['error' => mb_substr($error, 0, 255), 'failed_at' => now()])->save();
    }
}
