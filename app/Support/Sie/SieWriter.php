<?php

declare(strict_types=1);

namespace App\Support\Sie;

use HashContext;
use LogicException;

/**
 * Writes SIE records to a stream in codepage 437 with CRLF line endings,
 * optionally wrapped in a #KSUMMA control sum (§10).
 *
 * Fields: an int or a code-like string (digits, letters, "." and "-") is
 * written bare, any other string and every SieText quoted, null as "" and an
 * array as an object list. Trailing empty fields are left out (§12).
 */
final class SieWriter
{
    private ?HashContext $crc = null;

    private bool $finished = false;

    /**
     * @param  resource  $stream
     */
    public function __construct(private $stream) {}

    public function beginChecksum(): void
    {
        if ($this->crc !== null) {
            throw new LogicException('The control sum has already begun.');
        }

        $this->line('#KSUMMA');
        $this->crc = hash_init('crc32b');
    }

    /**
     * @param  list<int|string|SieText|list<int|string|SieText>|null>  $fields
     */
    public function record(string $label, array $fields = []): void
    {
        $this->write($label, $fields, '');
    }

    /**
     * @param  list<int|string|SieText|list<int|string|SieText>|null>  $fields
     * @param  iterable<array{0: string, 1: list<int|string|SieText|list<int|string|SieText>|null>}>  $rows
     */
    public function voucher(array $fields, iterable $rows): void
    {
        $this->write('#VER', $fields, '');
        $this->line('{');

        foreach ($rows as [$label, $rowFields]) {
            $this->write($label, $rowFields, '   ');
        }

        $this->line('}');
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        if ($this->crc !== null) {
            $sum = hexdec(hash_final($this->crc));
            $this->crc = null;
            $this->line("#KSUMMA {$sum}");
        }
    }

    /**
     * @param  list<int|string|SieText|list<int|string|SieText>|null>  $fields
     */
    private function write(string $label, array $fields, string $indent): void
    {
        if ($this->finished) {
            throw new LogicException('The SIE file is already finished.');
        }

        while ($fields !== [] && end($fields) === null) {
            array_pop($fields);
        }

        $parts = [$label];
        $this->hash($label);

        foreach ($fields as $field) {
            if (is_array($field)) {
                $items = array_map(fn (int|string|SieText $item): string => $this->field($item), $field);
                $parts[] = '{'.implode(' ', $items).'}';

                continue;
            }

            $parts[] = $this->field($field);
        }

        $this->line($indent.implode(' ', $parts));
    }

    private function field(int|string|SieText|null $field): string
    {
        $value = Cp437::encode(match (true) {
            $field instanceof SieText => $field->value,
            $field === null => '',
            default => (string) $field,
        });

        if (! $field instanceof SieText && preg_match('/^[A-Za-z0-9.\-]+$/', $value)) {
            $this->hash($value);

            return $value;
        }

        // SIE escapes only quotes, so a backslash right before a quote or at
        // the end would be read back as an escaped quote.
        $value = (string) preg_replace('/\\\\(?=$|")/', '/', $value);
        $this->hash($value);

        return '"'.str_replace('"', '\\"', $value).'"';
    }

    private function hash(string $value): void
    {
        if ($this->crc !== null) {
            hash_update($this->crc, $value);
        }
    }

    private function line(string $text): void
    {
        fwrite($this->stream, $text."\r\n");
    }
}
