<?php

declare(strict_types=1);

namespace App\Support\Sie;

/**
 * A field the writer always quotes, such as a name or a description.
 */
final class SieText
{
    public function __construct(public readonly string $value) {}
}
