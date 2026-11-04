<?php

declare(strict_types=1);

namespace App\Modules\Crm\Jobs;

use App\Models\Company;
use App\Modules\Crm\Actions\SendMailing;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Writes a started mailing's recipients in bulk (ADR-048), off the request:
 * a segment can hold up to 20 000 people. Carries the company id and sets
 * the company itself (AGENTS.md §6.0). Idempotent: only a mailing that is
 * still PREPARING is prepared.
 */
class PrepareMailing implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $companyId, public readonly int $mailingId) {}

    public function handle(SendMailing $send): void
    {
        // The job knows only the id; the company is read across companies and the work runs inside it.
        $company = CompanyScope::bypass(fn (): ?Company => Company::query()->find($this->companyId));

        if ($company instanceof Company) {
            $send->prepare($company, $this->mailingId);
        }
    }
}
