<?php

declare(strict_types=1);

namespace App\Migration\Import\Jobs;

use App\Migration\Import\RegisterImportRunner;
use App\Models\Company;
use App\Models\RegisterImport;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads a register file in, in the background. Receives the company id and
 * sets it before anything is read (AGENTS.md §6.0); never relies on a session.
 */
class RunRegisterImport implements ShouldQueue
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

    public function handle(RegisterImportRunner $runner): void
    {
        CurrentCompany::run($this->companyId, function () use ($runner): void {
            $import = RegisterImport::query()->findOrFail($this->importId);
            $company = Company::query()->findOrFail($this->companyId);
            $user = User::query()->findOrFail($this->userId);

            $runner->run($import, $company, $user);
        });
    }
}
