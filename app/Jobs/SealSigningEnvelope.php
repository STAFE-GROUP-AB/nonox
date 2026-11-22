<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Signing\Seal\EnvelopeSealer;
use App\Domain\Signing\Seal\Sealing;
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
 * Seals the finished file of a completed envelope (ADR-046 amendment, gap 5,
 * package S10) in the background. A timestamp server that does not answer
 * releases the job with a growing delay (1, 2, 4 … at most 60 minutes) for up
 * to the configured hours; the last attempt seals without a timestamp. When
 * the job dies for good, failed() gives the seal up, so the envelope never
 * hangs and its completion mails always go. Idempotent: only a PENDING
 * envelope is sealed, under a row lock; signing:seal-check queues it again
 * if a worker lost it.
 */
class SealSigningEnvelope implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany {
        middleware as companyMiddleware;
    }

    /** The engine has a 60-second timeout per call, and sealing makes two calls. */
    public int $timeout = 300;

    public function __construct(public int $companyId, public string $envelopeId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [...$this->companyMiddleware(), (new WithoutOverlapping("signing-seal:{$this->envelopeId}"))->releaseAfter(60)->expireAfter(600)];
    }

    /** Attempts are bounded by time, not by count: the retry window plus a margin for the last attempt. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(Sealing::retryHours() + 2);
    }

    public function handle(EnvelopeSealer $sealer): void
    {
        $outcome = CurrentCompany::run($this->companyId, fn (): string => $sealer->attempt($this->envelopeId));

        if ($outcome === EnvelopeSealer::RETRY) {
            $this->release(self::delay($this->attempts()));
        }
    }

    /** Seconds before the next try: 60 after the first, doubling, at most an hour. */
    public static function delay(int $attempts): int
    {
        return (int) min(3600, 60 * (2 ** max(0, min(6, $attempts - 1))));
    }

    /** After the last attempt the seal is given up and the completion mails go with the unsealed file. */
    public function failed(?Throwable $exception = null): void
    {
        CurrentCompany::run($this->companyId, fn (): string => app(EnvelopeSealer::class)->fail($this->envelopeId, EnvelopeSealer::JOB_FAILED));
    }
}
