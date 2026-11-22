<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Signing\Seal\EnvelopeTimestamper;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * One step of long-term validation of a sealed file (ADR-046 amendment,
 * gap 5, package S11): its validation data after the seal (B-LT), or a
 * renewing document timestamp (B-LTA). Queued after a B-T seal and by the
 * nightly signing:timestamp.
 *
 * Unique per envelope (ShouldBeUnique, uniqueId = the envelope id), like
 * SealSigningEnvelope, so the nightly run never stacks a second job on one
 * still waiting, and WithoutOverlapping per envelope. An engine failure is
 * recorded on the envelope (ltv_failure) and the next night tries again; the
 * job retries only an uncaught exception (a database or storage error), with
 * backoff, and failed() records JOB_FAILED. Idempotent: a step is taken only
 * while it is due, and the result is stored under a row lock only when no
 * other version came first.
 */
class TimestampSigningEnvelope implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany {
        middleware as companyMiddleware;
    }

    /** The engine has a 60-second timeout per call; one step is one call. */
    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(public int $companyId, public string $envelopeId) {}

    public function uniqueId(): string
    {
        return $this->envelopeId;
    }

    /** Seconds the unique lock lives at most: the attempts and their backoff, with a margin. */
    public function uniqueFor(): int
    {
        return 6 * 3600;
    }

    /**
     * Seconds before another attempt after an uncaught exception: never at once.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [300, 1800];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [...$this->companyMiddleware(), (new WithoutOverlapping("signing-ltv:{$this->envelopeId}"))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(EnvelopeTimestamper $timestamper): void
    {
        CurrentCompany::run($this->companyId, fn (): string => $timestamper->attempt($this->envelopeId));
    }

    public function failed(?Throwable $exception = null): void
    {
        CurrentCompany::run($this->companyId, fn (): string => app(EnvelopeTimestamper::class)->recordFailure($this->envelopeId, EnvelopeTimestamper::JOB_FAILED));
    }
}
