<?php

declare(strict_types=1);

namespace App\Domain\Units;

use RuntimeException;

/** An entered quantity too large to be a stock quantity in the stock unit. */
final class QuantityOutOfRange extends RuntimeException {}
