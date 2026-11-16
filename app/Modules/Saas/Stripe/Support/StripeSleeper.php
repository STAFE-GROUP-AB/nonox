<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Support;

use Closure;

/**
 * How the Stripe client waits between requests. Bound in the container so
 * tests replace it with one that records the waits instead of sleeping.
 */
class StripeSleeper
{
    /** @var list<int> milliseconds asked for, for tests */
    public array $waits = [];

    public function __construct(public bool $sleeps = true) {}

    /** @return Closure(int): void */
    public function closure(): Closure
    {
        return function (int $milliseconds): void {
            $this->waits[] = $milliseconds;

            if ($this->sleeps && $milliseconds > 0) {
                usleep($milliseconds * 1000);
            }
        };
    }
}
