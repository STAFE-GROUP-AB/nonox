<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Demo\CreateDemo;
use App\Support\Demo\DemoSessions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Builds a visitor's demo company in the queue (ADR-077). The visitor waits
 * on /prova/bygger; this marks the token ready or failed. One attempt: a
 * failed build is not retried, the visitor starts another.
 */
class BuildDemoCompany implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $token, public readonly string $industry) {}

    public function handle(CreateDemo $create): void
    {
        DemoSessions::startBeating($this->token);

        try {
            $guest = $create($this->industry, CarbonImmutable::now()->addHours((int) config('nonox.demo.hours')));

            DemoSessions::markReady($this->token, $guest);
        } catch (Throwable $exception) {
            report($exception);
            DemoSessions::markFailed($this->token);
        } finally {
            DemoSessions::finishedBuilding($this->token);
        }
    }
}
