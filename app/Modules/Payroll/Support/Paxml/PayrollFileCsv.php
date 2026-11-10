<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Paxml;

use App\Modules\Payroll\Support\Migration\PayrollCsv;
use Illuminate\Validation\ValidationException;

/**
 * The simple CSV layout for payroll files, for systems without PAXml
 * (PAYROLL_MODULE.md, "Löneunderlag från schemasystem"): semicolon
 * separated, the first line naming the columns, UTF-8 or Windows-1252.
 *
 *   anstallningsnummer;personnummer;loneart;benamning;datum;datum_tom;antal;apris;belopp
 *
 * loneart is required, and anstallningsnummer or personnummer; the rest may
 * be left out. Numbers take a decimal comma or point.
 */
final class PayrollFileCsv
{
    public const array OPTIONAL = ['anstallningsnummer', 'personnummer', 'benamning', 'datum', 'datum_tom', 'antal', 'apris', 'belopp'];

    /** Every column in the documented order, for the template a payroll program's export is copied into. */
    public const array COLUMNS = ['anstallningsnummer', 'personnummer', 'loneart', 'benamning', 'datum', 'datum_tom', 'antal', 'apris', 'belopp'];

    public function read(string $contents): PayrollFile
    {
        if (strlen($contents) > PaxmlReader::MAX_BYTES) {
            throw new UnreadablePayrollFile(__('Filen är större än 10 MB.'));
        }

        try {
            $rows = PayrollCsv::read($contents, ['loneart'], self::OPTIONAL);
        } catch (ValidationException $exception) {
            throw new UnreadablePayrollFile((string) collect($exception->errors())->flatten()->first());
        }

        if (! array_key_exists('anstallningsnummer', $rows[0]) && ! array_key_exists('personnummer', $rows[0])) {
            throw new UnreadablePayrollFile(__('Filen behöver kolumnen anstallningsnummer eller personnummer.'));
        }

        if (count($rows) > PaxmlReader::MAX_LINES) {
            throw new UnreadablePayrollFile(__('Filen har fler än :max transaktioner. Dela upp den.', ['max' => number_format(PaxmlReader::MAX_LINES, 0, ',', ' ')]));
        }

        $lines = [];

        foreach ($rows as $index => $row) {
            $from = self::blank($row['datum'] ?? null);

            $lines[] = new PayrollFileLine(
                lineNo: $index + 1,
                section: PayrollFileLine::CSV,
                employeeRef: self::blank($row['anstallningsnummer'] ?? null),
                personalNumber: self::blank($row['personnummer'] ?? null),
                code: self::blank($row['loneart']),
                description: self::blank($row['benamning'] ?? null),
                dateFrom: $from,
                dateTo: self::blank($row['datum_tom'] ?? null) ?? $from,
                quantity: self::number($row['antal'] ?? null),
                rate: self::number($row['apris'] ?? null),
                amount: self::number($row['belopp'] ?? null),
            );
        }

        return new PayrollFile('CSV', null, $lines);
    }

    private static function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /** 1 234,50 → 1234.50; text that is not a number is passed on and refused later. */
    private static function number(?string $value): ?string
    {
        $value = self::blank($value);

        return $value === null ? null : str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $value);
    }
}
