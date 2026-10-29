<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\Reports\LedgerReports;
use App\Domain\Accounting\Reports\ReportFilters;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The report on the screen as a CSV file that Excel opens directly: UTF-8
 * with a byte order mark, semicolons, decimal commas.
 */
class ReportCsvController
{
    public function __invoke(Request $request, LedgerReports $reports): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->hasTeamPermission($user->currentCompany(), 'reports:view'), 403);

        $filters = ReportFilters::fromInput($request->query());
        abort_if($filters === null, 404);

        $rows = $this->rows($filters, $reports);
        $name = "{$filters->report}_{$filters->from->toDateString()}_{$filters->to->toDateString()}.csv";

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @return list<list<string|int>>
     */
    private function rows(ReportFilters $filters, LedgerReports $reports): array
    {
        $query = $filters->query();
        $kr = fn (int $ore): string => ($ore < 0 ? '-' : '').intdiv(abs($ore), 100).','.str_pad((string) (abs($ore) % 100), 2, '0', STR_PAD_LEFT);

        switch ($filters->report) {
            case 'resultat':
                $data = $reports->incomeStatement($query, $filters->compare ? LedgerReports::previousPeriod($query) : null);
                $rows = [[$this->label('Grupp'), $this->label('Konto'), $this->label('Namn'), $this->label('Perioden'), $this->label('Förra året')]];

                foreach ($data['groups'] as $group) {
                    foreach ($group['rows'] as $row) {
                        $rows[] = [$group['label'], $row['number'], $row['name'], $kr($row['amount']), $row['compare'] === null ? '' : $kr($row['compare'])];
                    }
                }

                $rows[] = [$this->label('Beräknat resultat'), '', '', $kr($data['result']), $data['compare_result'] === null ? '' : $kr($data['compare_result'])];

                return $rows;

            case 'balans':
                $data = $reports->balanceSheet($query);
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

                foreach ($reports->generalLedger($query, $filters->account) as $account) {
                    $rows[] = [$account['number'], '', '', $this->label('Ingående saldo'), '', '', $kr($account['opening'])];

                    foreach ($account['rows'] as $row) {
                        $rows[] = [$account['number'], $row['date'], $row['reference'], $row['description'], $kr($row['debit']), $kr($row['credit']), $kr($row['balance'])];
                    }
                }

                return $rows;

            case 'verifikationer':
                $rows = [[$this->label('Ver'), $this->label('Datum'), $this->label('Text'), $this->label('Konto'), $this->label('Kostnadsställe'), $this->label('Projekt'), $this->label('Debet'), $this->label('Kredit')]];

                foreach ($reports->voucherList($query) as $voucher) {
                    foreach ($voucher->rows as $row) {
                        $rows[] = [$voucher->reference(), $voucher->voucher_date->toDateString(), $voucher->description, (int) $row->account?->number, (string) $row->costCenter?->code, (string) $row->project?->code, $kr($row->debit_ore->ore), $kr($row->credit_ore->ore)];
                    }
                }

                return $rows;

            default:
                $data = $reports->trialBalance($query);
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
