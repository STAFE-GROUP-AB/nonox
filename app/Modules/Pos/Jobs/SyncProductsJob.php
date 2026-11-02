<?php

declare(strict_types=1);

namespace App\Modules\Pos\Jobs;

use App\Modules\ModuleManager;
use App\Modules\Pos\Actions\SyncProducts;
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
 * Sends changed articles to one till (ADR-042); runs again while more are
 * waiting than one run sends.
 */
class SyncProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    /** @param  ?list<int>  $articleIds */
    public function __construct(public int $companyId, public int $connectionId, public ?array $articleIds = null) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetCompanyContext($this->companyId), (new WithoutOverlapping("pos-products:{$this->connectionId}"))->releaseAfter(30)->expireAfter(1800)];
    }

    public function handle(SyncProducts $sync, ModuleManager $modules): void
    {
        CurrentCompany::set($this->companyId);

        if (! $modules->isEnabled(PosModule::KEY, $this->companyId)) {
            return;
        }

        $connection = PosConnection::query()->find($this->connectionId);

        if (! $connection instanceof PosConnection) {
            return;
        }

        if ($sync($connection, $this->articleIds)['more']) {
            self::dispatch($this->companyId, $this->connectionId, $this->articleIds);
        }
    }
}
