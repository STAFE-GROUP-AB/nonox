<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Signing\Actions\ProcessSigningBulkSend;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * A few more envelopes of a confirmed bulk send for signing (ADR-046 S15). The
 * job carries the company id and runs inside it; it queues itself for the
 * rest (with a delay while the mail quota is used up), so a stopped worker
 * is resumed by dispatching it again. One job per run at a time.
 */
class RunSigningBulkSend implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany {
        middleware as companyMiddleware;
    }

    public int $tries = 3;

    public function __construct(public int $companyId, public string $bulkSendId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [...$this->companyMiddleware(), (new WithoutOverlapping("signing-bulk-send:{$this->bulkSendId}"))->releaseAfter(30)->expireAfter(900)];
    }

    public function handle(ProcessSigningBulkSend $process): void
    {
        $process($this->companyId, $this->bulkSendId);
    }
}
