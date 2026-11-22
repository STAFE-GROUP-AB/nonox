<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Modules\Signing\Actions\ManageSigningBulkSends;
use App\Modules\Signing\Actions\ProcessSigningBulkSend;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\RunsForCompany;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

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

    /** Seconds between attempts after an error; a released job (overlap, quota) is not an attempt that counts, retryUntil rules. */
    public int $backoff = 60;

    public function __construct(public int $companyId, public string $bulkSendId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [...$this->companyMiddleware(), (new WithoutOverlapping("signing-bulk-send:{$this->bulkSendId}"))->releaseAfter(30)->expireAfter(900)];
    }

    /** Attempts are bounded by time, not by count: a job released by the overlap guard must not use them up. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    /** After the last attempt the run is stopped, its unsent rows skipped and every payload emptied; it never hangs. */
    public function failed(?Throwable $exception = null): void
    {
        CurrentCompany::run($this->companyId, function (): void {
            $run = SigningBulkSend::query()->find($this->bulkSendId);

            if ($run instanceof SigningBulkSend && $run->status === SigningBulkSend::RUNNING) {
                app(ManageSigningBulkSends::class)->stop($run, 'signing.bulk_send_cancelled', __('Massutskicket stoppades: ett fel gjorde att det inte gick att fortsätta.'), Company::query()->findOrFail($this->companyId), null);
            }
        });
    }

    public function handle(ProcessSigningBulkSend $process): void
    {
        $process($this->companyId, $this->bulkSendId);
    }
}
