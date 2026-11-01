<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use RuntimeException;

/** The bank no longer accepts the consent: it expired or was withdrawn. */
final class ConsentEnded extends RuntimeException {}
