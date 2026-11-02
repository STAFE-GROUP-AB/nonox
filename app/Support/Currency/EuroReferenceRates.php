<?php

declare(strict_types=1);

namespace App\Support\Currency;

use Illuminate\Container\Attributes\Bind;

/**
 * The euro reference rates of the European Central Bank: units of a
 * currency per euro. EcbRates fetches them; tests bind a fake
 * (Tests\Support\FakeEuroRates, bound in Tests\TestCase).
 */
#[Bind(EcbRates::class)]
interface EuroReferenceRates
{
    /**
     * The rate published for $date (Y-m-d), or, when none is published that
     * day (weekends, TARGET holidays), the next one published after it.
     * Null when none is published yet or the source cannot be reached.
     */
    public function onOrAfter(string $currency, string $date): ?EuroRate;
}
