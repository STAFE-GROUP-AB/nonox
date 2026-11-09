<?php

declare(strict_types=1);

namespace App\Migration\Import;

use RuntimeException;

/** A cell that cannot be read; the message is for the validation report. */
final class RowProblem extends RuntimeException {}
