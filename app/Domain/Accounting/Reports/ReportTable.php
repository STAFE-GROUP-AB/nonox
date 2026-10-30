<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

/**
 * A ledger report as a table of rows with translated headings and amounts
 * as Swedish text (decimal comma), for the CSV download and the archive
 * package.
 */
final class ReportTable
{
    /** Swedish file name and title per report key. */
    public const array TITLES = [
        'saldobalans' => 'Saldobalans',
        'resultat' => 'Resultatrapport',
        'balans' => 'Balansrapport',
        'huvudbok' => 'Huvudbok',
        'verifikationer' => 'Verifikationslista',
    ];

    public function __construct(private readonly LedgerReports $reports) {}

    /**
     * @return list<list<string|int>>
     */
    public function rows(string $report, LedgerQuery $query, ?int $account = null, bool $compare = false): array
    {
        $kr = fn (int $ore): string => ($ore < 0 ? '-' : '').intdiv(abs($ore), 100).','.str_pad((string) (abs($ore) % 100), 2, '0', STR_PAD_LEFT);

        switch ($report) {
            case 'resultat':
                $data = $this->reports->incomeStatement($query, $compare ? LedgerReports::previousPeriod($query) : null);
                $rows = [[$this->label('Grupp'), $this->label('Konto'), $this->label('Namn'), $this->label('Perioden'), $this->label('Förra året')]];

                foreach ($data['groups'] as $group) {
                    foreach ($group['rows'] as $row) {
                        $rows[] = [$group['label'], $row['number'], $row['name'], $kr($row['amount']), $row['compare'] === null ? '' : $kr($row['compare'])];
                    }
                }

                $rows[] = [$this->label('Beräknat resultat'), '', '', $kr($data['result']), $data['compare_result'] === null ? '' : $kr($data['compare_result'])];

                return $rows;

            case 'balans':
                $data = $this->reports->balanceSheet($query);
                $rows = [[$this->label('Grupp'), $this->label('Konto'), $this->label('Namn'), $this->label('Ingående'), $this->label('Förändring'), $this->label('Utgående')]];

                foreach ($data['sides'] as $side) {
                    foreach ($side['groups'] as $group) {
                        foreach ($group['rows'] as $row) {
                            $rows[] = [$group['label'], $row['number'], $row['name'], $kr($row['opening']), $kr($row['change']), $kr($row['closing'])];
                        }
                    }
                }

                $result = $data['unbooked_result'];
                $rows[] = [$this->label('Beräknat resultat, ännu inte bokfört'), '', '', $kr($result['opening']), $kr($result['change']), $kr($result['closing'])];

                return $rows;

            case 'huvudbok':
                $rows = [[$this->label('Konto'), $this->label('Datum'), $this->label('Ver'), $this->label('Text'), $this->label('Debet'), $this->label('Kredit'), $this->label('Saldo')]];

                foreach ($this->reports->generalLedger($query, $account) as $account) {
                    $rows[] = [$account['number'], '', '', $this->label('Ingående saldo'), '', '', $kr($account['opening'])];

                    foreach ($account['rows'] as $row) {
                        $rows[] = [$account['number'], $row['date'], $row['reference'], $row['description'], $kr($row['debit']), $kr($row['credit']), $kr($row['balance'])];
                    }
                }

                return $rows;

            case 'verifikationer':
                $rows = [[$this->label('Ver'), $this->label('Datum'), $this->label('Text'), $this->label('Konto'), $this->label('Kostnadsställe'), $this->label('Projekt'), $this->label('Debet'), $this->label('Kredit')]];

                foreach ($this->reports->voucherList($query) as $voucher) {
                    foreach ($voucher->rows as $row) {
                        $rows[] = [$voucher->reference(), $voucher->voucher_date->toDateString(), $voucher->description, (int) $row->account?->number, (string) $row->costCenter?->code, (string) $row->project?->code, $kr($row->debit_ore->ore), $kr($row->credit_ore->ore)];
                    }
                }

                return $rows;

            default:
                $data = $this->reports->trialBalance($query);
                $rows = [[$this->label('Konto'), $this->label('Namn'), $this->label('Ingående'), $this->label('Debet'), $this->label('Kredit'), $this->label('Utgående')]];

                foreach ($data['rows'] as $row) {
                    $rows[] = [$row['number'], $row['name'], $kr($row['opening']), $kr($row['debit']), $kr($row['credit']), $kr($row['closing'])];
                }

                return $rows;
        }
    }

    /**
     * A translated column heading.
     */
    private function label(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
