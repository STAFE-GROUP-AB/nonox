<?php

declare(strict_types=1);

namespace App\Migration\Import;

/**
 * What an import will do with one row of the file (ADR-076).
 */
final class RowPlan
{
    public const string CREATE = 'create';

    /** An existing record gets only its empty fields filled. */
    public const string FILL = 'fill';

    /** The record already exists and is left as it is. */
    public const string EXISTS = 'exists';

    /** The same record appears earlier in the file. */
    public const string DUPLICATE = 'duplicate';

    public const string EXCLUDED = 'excluded';

    public const string ERROR = 'error';

    /**
     * @param  array<string, mixed>  $values  normalised input for the Save action
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $fill  for FILL: the fields to fill
     * @param  array<string, string>  $unmapped  coded values that could not be read: field => source value
     */
    public function __construct(
        public readonly int $line,
        public readonly string $status,
        public readonly array $values,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly int|string|null $existingId = null,
        public readonly int|string|null $partyId = null,
        public readonly array $fill = [],
        public readonly array $unmapped = [],
    ) {}

    /** Whether the row is counted towards the open-invoice sums. */
    public function countsAsOpenItem(): bool
    {
        return in_array($this->status, [self::CREATE, self::EXISTS], true);
    }
}
