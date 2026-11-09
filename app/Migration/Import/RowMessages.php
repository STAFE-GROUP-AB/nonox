<?php

declare(strict_types=1);

namespace App\Migration\Import;

/**
 * The problems found in one row of a register file: errors skip the row,
 * warnings are only shown (ADR-076).
 */
final class RowMessages
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @param  array<array-key, mixed>|string|null  $message  as a translation helper returns it */
    public function error(array|string|null $message): void
    {
        $this->errors[] = is_string($message) ? $message : '';
    }

    /** @param  array<array-key, mixed>|string|null  $message  as a translation helper returns it */
    public function warn(array|string|null $message): void
    {
        $this->warnings[] = is_string($message) ? $message : '';
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
