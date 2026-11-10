<?php

declare(strict_types=1);

namespace App\Domain\Units;

use RuntimeException;

/** An entry that is zero milli of the stock unit after conversion is refused (ADR-071 §12.4). */
final class QuantityRoundsToZero extends RuntimeException {}
