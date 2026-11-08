<?php

declare(strict_types=1);

namespace App\Migration\Import;

use Closure;
use Generator;

/**
 * A register file opened for reading: what was detected about it, the
 * header row and a lazy stream of the data rows (ADR-076). Cells are always
 * text; a cell that starts with =, +, - or @ is just that text and is never
 * evaluated.
 */
final class RegisterTable
{
    /**
     * @param  list<string>  $headers
     * @param  list<string>  $sheets  worksheet names (xlsx), empty for text files
     * @param  Closure(): Generator<int, list<string>>  $source  yields line number => cells for the data rows
     */
    public function __construct(
        public readonly string $format,
        public readonly ?string $encoding,
        public readonly ?string $delimiter,
        public readonly array $sheets,
        public readonly ?string $sheet,
        public readonly array $headers,
        private readonly Closure $source,
    ) {}

    /** @return Generator<int, list<string>> */
    public function rows(): Generator
    {
        yield from ($this->source)();
    }

    /**
     * What was detected, for the page and the stored options.
     *
     * @return array<string, mixed>
     */
    public function detected(): array
    {
        return [
            'format' => $this->format,
            'encoding' => $this->encoding,
            'delimiter' => $this->delimiter,
            'sheets' => $this->sheets,
            'sheet' => $this->sheet,
            'headers' => $this->headers,
        ];
    }
}
