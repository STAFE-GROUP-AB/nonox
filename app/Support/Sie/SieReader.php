<?php

declare(strict_types=1);

namespace App\Support\Sie;

use Generator;
use HashContext;
use RuntimeException;

/**
 * Reads a SIE file one record at a time, so a file of hundreds of megabytes
 * never has to fit in memory. Text is decoded from codepage 437 (§5.8); a
 * line that is valid UTF-8 with non-ASCII characters is taken as UTF-8,
 * since some programs write that despite "#FORMAT PC8".
 *
 * Problems that do not stop reading (an unknown line, a broken quote) are
 * collected in problems(), in Swedish, with their line number. The control
 * sum is known once every record has been read.
 */
final class SieReader
{
    /** @var list<string> */
    private array $problems = [];

    private SieChecksum $checksum = SieChecksum::Absent;

    private ?HashContext $crc = null;

    private int $utf8Lines = 0;

    public function __construct(private readonly string $path) {}

    /**
     * @return Generator<int, SieRecord>
     */
    public function records(): Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open the SIE file {$this->path}.");
        }

        $this->problems = [];
        $this->checksum = SieChecksum::Absent;
        $this->crc = null;
        $this->utf8Lines = 0;

        $number = 0;
        $voucher = null;
        $inside = false;

        try {
            while (($line = fgets($handle)) !== false) {
                $number++;
                $line = rtrim($line, "\r\n");

                if ($number === 1 && str_starts_with($line, "\xEF\xBB\xBF")) {
                    $line = substr($line, 3);
                }

                $trimmed = trim($line, " \t");

                if ($trimmed === '') {
                    continue;
                }

                if ($trimmed === '{') {
                    if ($voucher === null || $inside) {
                        $this->problem($number, __('en klammer { utan verifikation före.'));

                        continue;
                    }

                    $inside = true;

                    continue;
                }

                if ($trimmed === '}') {
                    if (! $inside || $voucher === null) {
                        $this->problem($number, __('en klammer } utan verifikation före.'));

                        continue;
                    }

                    yield $voucher;
                    $voucher = null;
                    $inside = false;

                    continue;
                }

                $record = $this->parse($line, $number);

                if ($record === null) {
                    continue;
                }

                if ($inside && $voucher !== null) {
                    $voucher->rows[] = $record;

                    continue;
                }

                if ($voucher !== null) {
                    $this->problem($voucher->line, __('verifikationen saknar rader inom { }.'));
                    $voucher = null;
                }

                if ($record->label === '#VER') {
                    $voucher = $record;

                    continue;
                }

                if ($record->label !== '#KSUMMA') {
                    yield $record;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($voucher !== null) {
            $this->problem($voucher->line, $inside
                ? __('filen tar slut mitt i en verifikation. Filen verkar vara ofullständig.')
                : __('verifikationen saknar rader inom { }.'));
        }

        if ($this->crc !== null) {
            $this->checksum = SieChecksum::Unterminated;
            $this->problems[] = __('Filen har en inledande kontrollsumma (#KSUMMA) men ingen avslutande. Filen verkar vara ofullständig.');
        }
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    public function checksum(): SieChecksum
    {
        return $this->checksum;
    }

    /**
     * Lines read as UTF-8 rather than codepage 437.
     */
    public function utf8Lines(): int
    {
        return $this->utf8Lines;
    }

    private function parse(string $line, int $number): ?SieRecord
    {
        try {
            $raw = SieTokenizer::tokenize($line);
        } catch (SieSyntaxError) {
            $this->problem($number, __('raden går inte att läsa (ett citattecken eller en klammer är inte avslutad).'));

            return null;
        }

        $label = $raw[0] ?? null;

        if (! is_string($label) || ! str_starts_with($label, '#')) {
            $this->problem($number, __('raden börjar inte med en etikett som #VER eller #KONTO.'));

            return null;
        }

        $label = strtoupper($label);
        $fields = array_slice($raw, 1);
        $utf8 = preg_match('/[\x80-\xFF]/', $line) === 1 && mb_check_encoding($line, 'UTF-8');

        if ($utf8) {
            $this->utf8Lines++;
        }

        if ($label === '#KSUMMA') {
            $this->checksumRecord($fields, $number);
        } elseif ($this->crc !== null) {
            $this->accumulate($label, $fields, $utf8);
        }

        return new SieRecord($label, $this->decode($fields, $utf8), $number);
    }

    /**
     * @param  list<string|list<string>>  $fields
     * @return list<string|list<string>>
     */
    private function decode(array $fields, bool $utf8): array
    {
        if ($utf8) {
            return $fields;
        }

        return array_map(
            fn (string|array $field): string|array => is_array($field) ? array_map(Cp437::decode(...), $field) : Cp437::decode($field),
            $fields,
        );
    }

    /**
     * @param  list<string|list<string>>  $fields
     */
    private function accumulate(string $label, array $fields, bool $utf8): void
    {
        assert($this->crc !== null);

        hash_update($this->crc, $label);

        foreach ($fields as $field) {
            foreach ((array) $field as $value) {
                hash_update($this->crc, $utf8 ? Cp437::encode($value) : $value);
            }
        }
    }

    /**
     * @param  list<string|list<string>>  $fields
     */
    private function checksumRecord(array $fields, int $number): void
    {
        $value = $fields[0] ?? null;

        if ($value === null) {
            if ($this->crc !== null || $this->checksum !== SieChecksum::Absent) {
                $this->problem($number, __('en extra inledande #KSUMMA.'));

                return;
            }

            $this->crc = hash_init('crc32b');

            return;
        }

        if ($this->crc === null) {
            $this->problem($number, __('en avslutande #KSUMMA utan inledande.'));

            return;
        }

        $actual = hexdec(hash_final($this->crc));
        $this->crc = null;

        $expected = is_string($value) && preg_match('/^-?\d{1,10}$/', $value) ? (int) $value : null;

        if ($expected !== null && $expected < 0) {
            $expected += 2 ** 32;
        }

        $this->checksum = $expected === $actual ? SieChecksum::Valid : SieChecksum::Invalid;

        if ($this->checksum === SieChecksum::Invalid) {
            $this->problems[] = __('Kontrollsumman (#KSUMMA) stämmer inte. Filen har ändrats eller skadats efter att den skapades.');
        }
    }

    private function problem(int $line, string $message): void
    {
        $this->problems[] = __('Rad :line: :message', ['line' => $line, 'message' => $message]);
    }
}
