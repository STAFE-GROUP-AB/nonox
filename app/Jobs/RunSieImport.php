<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Accounting\ImportSie;
use App\Domain\Accounting\SieImport\SieImportRefused;
use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Models\SieImport;
use App\Support\Tenancy\RunsForCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads a confirmed SIE file in, in the background. Runs once: a failed
 * import has changed nothing, and the user can simply try again.
 */
class RunSieImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use RunsForCompany;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $companyId, public int $importId) {}

    public function handle(ImportSie $importSie): void
    {
        $import = SieImport::query()->findOrFail($this->importId);

        $started = SieImport::query()
            ->whereKey($import->id)
            ->where('status', SieImportStatus::Queued->value)
            ->update(['status' => SieImportStatus::Running->value, 'started_at' => CarbonImmutable::now()]);

        if ($started !== 1) {
            return;
        }

        try {
            $importSie($import->fresh() ?? $import);
        } catch (SieImportRefused $refused) {
            $this->markFailed($import, implode("\n", $refused->reasons), $refused->result);
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($import, __('Något oväntat gick fel under inläsningen. Ingenting har ändrats. Försök igen, och hör av dig om det händer igen.'));
        } finally {
            Cache::forget(ImportSie::progressKey($import));
        }
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function markFailed(SieImport $import, string $error, ?array $result = null): void
    {
        SieImport::query()->whereKey($import->id)->update([
            'status' => SieImportStatus::Failed->value,
            'error' => $error,
            'result' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR),
            'finished_at' => CarbonImmutable::now(),
        ]);
    }
}
