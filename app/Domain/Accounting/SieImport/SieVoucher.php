<?php

declare(strict_types=1);

namespace App\Domain\Accounting\SieImport;

use App\Support\Sie\SieDate;
use App\Support\Sie\SieRecord;
use Carbon\CarbonImmutable;

/**
 * A #VER with its rows, read from a SIE file (SIE 4C §11):
 * serie vernr verdatum vertext regdatum sign.
 *
 * Rows marked #RTRANS were added later and are part of the voucher; rows
 * marked #BTRANS were removed and are not. Both are kept as history.
 */
final class SieVoucher
{
    public const int TEXT_LENGTH = 255;

    /** @var list<SieTransaction> */
    public array $rows = [];

    /** @var list<array{after: int, row: SieTransaction}> */
    public array $removed = [];

    /** @var list<string> */
    public array $problems = [];

    public bool $truncated = false;

    private function __construct(
        public readonly string $series,
        public readonly string $number,
        public readonly ?CarbonImmutable $date,
        public readonly string $text,
        public readonly ?CarbonImmutable $registeredOn,
        public readonly ?string $signature,
        public readonly int $line,
    ) {}

    public static function fromRecord(SieRecord $record): self
    {
        $text = (string) $record->field(3);
        $registeredOn = (string) $record->field(4);
        $signature = (string) $record->field(5);

        $voucher = new self(
            series: (string) $record->field(0),
            number: (string) $record->field(1),
            date: SieDate::parse((string) $record->field(2)),
            text: mb_substr($text, 0, self::TEXT_LENGTH),
            registeredOn: $registeredOn === '' ? null : SieDate::parse($registeredOn),
            signature: $signature === '' ? null : mb_substr($signature, 0, 255),
            line: $record->line,
        );

        $voucher->truncated = mb_strlen($text) > self::TEXT_LENGTH;

        if ($voucher->date === null) {
            $voucher->problems[] = __('datumet ":date" går inte att läsa.', ['date' => (string) $record->field(2)]);
        }

        $voucher->readRows($record->rows);

        return $voucher;
    }

    /**
     * "A12", or the line number when the file gives no series or number.
     */
    public function reference(): string
    {
        $reference = $this->series.$this->number;

        return $reference !== '' ? $reference : __('på rad :line', ['line' => $this->line]);
    }

    public function debit(): int
    {
        return array_sum(array_map(fn (SieTransaction $row): int => $row->debit(), $this->rows));
    }

    public function credit(): int
    {
        return array_sum(array_map(fn (SieTransaction $row): int => $row->credit(), $this->rows));
    }

    /**
     * @param  list<SieRecord>  $records
     */
    private function readRows(array $records): void
    {
        $pendingCopy = null;

        foreach ($records as $record) {
            if (! in_array($record->label, ['#TRANS', '#RTRANS', '#BTRANS'], true)) {
                $this->problems[] = __('posten :label hör inte hemma i en verifikation.', ['label' => $record->label]);

                continue;
            }

            $row = SieTransaction::fromRecord($record);

            if (is_string($row)) {
                $this->problems[] = __('rad :line: :problem', ['line' => $record->line, 'problem' => $row]);

                continue;
            }

            $this->truncated = $this->truncated || $row->truncated;

            if ($row->label === '#TRANS' && $pendingCopy !== null && $row->sameAs($pendingCopy)) {
                $pendingCopy = null;

                continue;
            }

            $pendingCopy = $row->label === '#RTRANS' ? $row : null;

            if ($row->label === '#BTRANS') {
                $this->removed[] = ['after' => count($this->rows), 'row' => $row];

                continue;
            }

            $this->rows[] = $row;
        }
    }
}
