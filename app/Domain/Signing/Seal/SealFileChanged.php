<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

use RuntimeException;

/** The signed version on disk no longer has the SHA-256 that was recorded: it is never sealed (ADR-046 S10). */
final class SealFileChanged extends RuntimeException {}
