<?php

declare(strict_types=1);

namespace App\Modules\Pos\Jobs;

use App\Modules\ModuleManager;
use App\Modules\Pos\Actions\PushStock;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\PosModule;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\SetCompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends a connection's waiting stock changes (ADR-042), one run per
 * connection at a time, retried with growing pauses when the till is busy
 * or down. The company is given explicitly (AGENTS.md §6.0).
 */
class PushStockJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800, 3600];

    public function __construct(public int $companyId, public int $connectionId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetCompanyContext($this->companyId), (new WithoutOverlapping("pos-stock:{$this->connectionId}"))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(PushStock $push, ModuleManager $modules): void
    {
        CurrentCompany::set($this->companyId);

        if (! $modules->isEnabled(PosModule::KEY, $this->companyId)) {
            return;
        }

        $connection = PosConnection::query()->find($this->connectionId);

        if (! $connection instanceof PosConnection) {
            return;
        }

        if ($push($connection) === PushStock::BATCH) {
            self::dispatch($this->companyId, $this->connectionId);
        }
    }
}
