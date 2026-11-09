<?php

declare(strict_types=1);

namespace App\Migration\Import\Jobs;

use App\Migration\Import\RegisterImportRunner;
use App\Models\Company;
use App\Models\RegisterImport;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Reads a register file in, in the background. Receives the company id and
 * sets it before anything is read (AGENTS.md §6.0); never relies on a session.
 */
class RunRegisterImport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $importId,
        public readonly int $companyId,
        public readonly int $userId,
    ) {}

    /** One run per import, whatever happens to the queue. */
    public function uniqueId(): string
    {
        return 'register-import-'.$this->importId;
    }

    public function handle(RegisterImportRunner $runner): void
    {
        CurrentCompany::run($this->companyId, function () use ($runner): void {
            $import = RegisterImport::query()->findOrFail($this->importId);

            // Only a run that confirmation started owns the import: not one that has finished, or been stopped.
            if ($import->status !== RegisterImport::RUNNING || $import->finished_at !== null) {
                return;
            }

            $company = Company::query()->findOrFail($this->companyId);
            $user = User::query()->findOrFail($this->userId);

            $runner->run($import, $company, $user);
        });
    }

    /** A job that dies (timeout, worker killed) ends the import in FAILED, not in RUNNING for ever. */
    public function failed(?Throwable $exception): void
    {
        CurrentCompany::run($this->companyId, function (): void {
            RegisterImport::query()->whereKey($this->importId)->where('status', RegisterImport::RUNNING)->update([
                'status' => RegisterImport::FAILED,
                'finished_at' => now(),
                'error' => __('Importen avbröts. Det som redan lästs in finns kvar. Läs in filen igen: poster som redan finns hoppas över.'),
            ]);
        });
    }
}
