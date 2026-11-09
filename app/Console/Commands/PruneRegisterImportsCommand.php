<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accounting\ImportSie;
use App\Models\RegisterImport;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the uploaded source files of register imports (ADR-076, section 7):
 * 30 days after an import has finished, 7 days after a preview that was never
 * confirmed. Only files under register-import/ are touched, never anything
 * else on the disk. The import row stays with its counts and hash, without
 * the file, so the audit trail keeps what happened.
 */
class PruneRegisterImportsCommand extends Command
{
    public const string DIRECTORY = 'register-import/';

    public const int FINISHED_DAYS = 30;

    public const int ABANDONED_DAYS = 7;

    public const int STUCK_HOURS = 2;

    protected $signature = 'register-import:prune';

    protected $description = 'Raderar inlästa källfiler från flytt av register efter 30 dagar, ogranskade efter 7.';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $deleted = 0;

        // A run that has said RUNNING for more than two hours has died: end it in FAILED so the user can start again.
        $stuck = CompanyScope::bypass(fn (): int => RegisterImport::query()
            ->where('status', RegisterImport::RUNNING)
            ->where('started_at', '<', $now->subHours(self::STUCK_HOURS))
            ->update(['status' => RegisterImport::FAILED, 'finished_at' => $now, 'error' => __('Importen avbröts. Det som redan lästs in finns kvar. Läs in filen igen: poster som redan finns hoppas över.')]));

        // Housekeeping across all companies, in a command with no signed-in user: the scope would hide every row.
        CompanyScope::bypass(function () use ($now, &$deleted): void {
            RegisterImport::query()
                ->whereNotNull('file_path')
                ->whereNull('file_deleted_at')
                ->where(function ($query) use ($now): void {
                    $query->where(fn ($finished) => $finished->whereIn('status', [RegisterImport::COMPLETED, RegisterImport::FAILED])->where('finished_at', '<', $now->subDays(self::FINISHED_DAYS)))
                        ->orWhere(fn ($abandoned) => $abandoned->whereIn('status', [RegisterImport::MAPPING, RegisterImport::ANALYSED])->where('created_at', '<', $now->subDays(self::ABANDONED_DAYS)));
                })
                ->each(function (RegisterImport $import) use ($now, &$deleted): void {
                    $path = (string) $import->file_path;

                    if (str_starts_with($path, self::DIRECTORY) && ! str_contains($path, '..')) {
                        Storage::disk(ImportSie::DISK)->delete($path);
                        $deleted++;
                    }

                    $import->forceFill(['file_path' => null, 'file_deleted_at' => $now])->save();
                });
        });

        $this->comment("Raderade {$deleted} inlästa källfiler och avslutade {$stuck} fastnade importer.");

        return self::SUCCESS;
    }
}
