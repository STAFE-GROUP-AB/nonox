<?php

declare(strict_types=1);

namespace App\Http\Controllers\Group;

use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupReport;
use App\Models\Company;
use App\Models\CorporateGroupRun;
use App\Models\User;
use App\Support\Csv;
use App\Support\Toast;
use App\Support\WorkQuota;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Koncernrapporter as files (ADR-060, addendum 2026-11-07, 2.4 and 3.2
 * S2-1.6; ADR-081 E26): one stored run's consolidated income statement and
 * balance sheet as CSV (UTF-8 with a byte order mark, semicolons, decimal
 * commas, as the other CSV files) and as PDF (dompdf, ADR-002). Both carry
 * the label in their first line or on every page and in the file name.
 *
 * The run is looked up by id and then shown only if GroupReport lets the
 * user see it: a run of a group the user does not see, or a run withheld
 * from someone who sees only sums, answers 404 like a run that does not
 * exist. Downloads are read only and write no audit entry, as the
 * companies' own report files.
 */
class GroupReportController
{
    /** The label as a file name: never the bare word for statutory group accounts (ADR-060 2.4). */
    public const string FILE_PREFIX = 'intern-koncernrapport';

    public function csv(Request $request, int $run): StreamedResponse
    {
        $report = $this->report($request, $run);
        $rows = self::rows($report);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, Csv::row($row), ';', '"', '');
            }

            fclose($out);
        }, self::fileName($report, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function pdf(Request $request, int $run): Response|RedirectResponse
    {
        $report = $this->report($request, $run);
        $parent = Company::query()->whereKey($report['group']->parent_company_id)->firstOrFail();

        // Generated PDFs count against the parent's hourly quota, like every PDF the server renders.
        if (! WorkQuota::attempt($parent, 'pdf')) {
            Toast::flash(WorkQuota::message(), 'error');

            return back();
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('group.report-pdf', ['report' => $report])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.self::fileName($report, 'pdf').'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The file's lines: the label first, the run's facts, then both
     * statements with the columns the user may see.
     *
     * @param  array<string, mixed>  $report
     * @return list<list<string|int>>
     */
    public static function rows(array $report): array
    {
        $kr = fn (?int $ore): string => $ore === null ? '' : number_format($ore / 100, 2, ',', '');
        $detailed = (bool) $report['detailed'];
        $line = fn (string $number, string $label, array $columns): array => [
            $number,
            $label,
            ...($detailed ? [$kr($columns['companies']), $kr($columns['eliminations'])] : []),
            $kr($columns['group']),
        ];
        $head = [__('Konto'), __('Benämning'), ...($detailed ? [__('Företagen'), __('Elimineringar')] : []), __('Koncernen')];

        $rows = [
            [(string) $report['label']],
            [__('Koncern'), (string) $report['group']->name],
            [__('Period'), (string) $report['from'], (string) $report['to']],
            [__('Körning'), (string) $report['run_id'], $report['created_at']->timezone('Europe/Stockholm')->format('Y-m-d H:i')],
            [__('Status'), $report['preliminary'] ? __('Preliminär') : __('Låsta perioder')],
            [__('Företag som ingår'), implode(', ', $report['members'])],
            [__('Kontrollsumma för indata'), (string) $report['inputs_hash']],
            [__('Kontrollsumma för resultatet'), (string) $report['output_hash']],
        ];

        if (! $report['intact'] || ! $report['entries_intact'] || ! $report['consistent']) {
            $rows[] = [__('Körningen eller koncernjournalen stämmer inte med sina kontrollsummor. Använd inte siffrorna och kontakta den som driver NONOX.')];
        }

        $rows[] = [];
        $rows[] = [__('Resultaträkning')];
        $rows[] = $head;

        foreach ($report['income']['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $rows[] = $line((string) $row['number'], (string) $row['name'], $row);
            }

            if ($group['rows'] !== []) {
                $rows[] = $line('', __('Summa :group', ['group' => mb_strtolower($group['label'])]), $group['total']);
            }

            if ($group['subtotal'] !== null) {
                $rows[] = $line('', (string) $group['subtotal']['label'], $group['subtotal']);
            }
        }

        $rows[] = $line('', __('Periodens resultat'), $report['income']['result']);
        $rows[] = [];
        $rows[] = [__('Balansräkning :date', ['date' => $report['to']])];
        $rows[] = $head;

        foreach ($report['balance'] as $key => $side) {
            foreach ($side['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $rows[] = $line((string) $row['number'], (string) $row['name'], $row);
                }
            }

            if ($key === 'equity') {
                $rows[] = $line('', __('Årets resultat som ännu inte är bokfört mot eget kapital'), $side['unbooked']);
            }

            $rows[] = $line('', __('Summa :side', ['side' => mb_strtolower($side['label'])]), $side['total']);
        }

        $rows[] = [];
        $rows[] = [__('Elimineringar per slag')];

        foreach ($report['eliminations'] as $elimination) {
            $rows[] = [(string) $elimination['label'], $elimination['amount'] === null
                ? trans_choice('{1} en post|[2,*] :count poster', $elimination['entries'], ['count' => $elimination['entries']])
                : $kr($elimination['amount'])];
        }

        // The label again as the last line, so a file cut short or read from the end still says what it is.
        $rows[] = [];
        $rows[] = [(string) $report['label']];

        return $rows;
    }

    /** @param  array<string, mixed>  $report */
    public static function fileName(array $report, string $extension): string
    {
        return self::FILE_PREFIX."_{$report['from']}_{$report['to']}_korning-{$report['run_id']}.{$extension}";
    }

    /** @return array<string, mixed> */
    private function report(Request $request, int $runId): array
    {
        /** @var User $user */
        $user = $request->user();
        $run = CorporateGroupRun::query()->with('runner:id,name')->findOrFail($runId);

        return GroupReport::build(GroupAccess::for($user), $run);
    }
}
