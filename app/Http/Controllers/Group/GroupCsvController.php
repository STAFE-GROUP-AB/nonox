<?php

declare(strict_types=1);

namespace App\Http\Controllers\Group;

use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupOverview;
use App\Livewire\Group\GroupOverviewPage;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The group overview as a CSV file that Excel opens directly (UTF-8 with a
 * byte order mark, semicolons, decimal commas). The group is looked up among
 * the user's own, so another user's id answers 404; the companies shown are
 * the ones the user may see reports of right now (ADR-060).
 */
class GroupCsvController
{
    public function __invoke(Request $request, int $group, GroupOverview $overview): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $access = GroupAccess::for($user);
        $found = $access->group($group);
        $tab = in_array($request->query('rapport'), GroupOverviewPage::TABS, true) && $request->query('rapport') !== 'installningar' ? (string) $request->query('rapport') : 'resultat';
        $year = is_string($request->query('ar')) ? $request->query('ar') : null;
        $till = is_string($request->query('till')) ? $request->query('till') : null;

        $report = $overview->build($access, $found, $year, $till);
        $rows = $this->rows($tab, $report);
        $name = 'koncern_'.$tab.'_'.($report['till']?->toDateString() ?? 'tom').'.csv';

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
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<string|int>>
     */
    private function rows(string $tab, array $report): array
    {
        $kr = fn (int $ore): string => number_format($ore / 100, 2, ',', '');
        $ids = array_column($report['companies'], 'id');
        $head = ['', ...array_column($report['companies'], 'name'), __('Summa')];
        $line = fn (string $label, array $amounts, int $sum): array => [$label, ...array_map(fn (int $id): string => $kr($amounts[$id] ?? 0), $ids), $kr($sum)];
        $rows = [[__('Intern koncernöversikt, inte en koncernredovisning'), '']];

        if ($tab === 'elimineringar') {
            $rows[] = [__('Förslag till elimineringar, ingenting är bokfört')];

            foreach ($report['eliminations'] as $pair) {
                $rows[] = [$pair['label'], $pair['left_label'], $kr($pair['left']), $pair['right_label'], $kr($pair['right']), __('Kan elimineras'), $kr($pair['eliminate']), __('Skillnad'), $kr($pair['difference'])];
            }

            $rows[] = [__('Summa'), __('Före'), __('Efter förslagen')];

            foreach ($report['adjusted'] as $total) {
                $rows[] = [$total['label'], $kr($total['before']), $kr($total['after'])];
            }

            return $rows;
        }

        $rows[] = $head;

        if ($tab === 'resultat') {
            foreach ($report['income']['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $rows[] = $line(GroupOverview::rowLabel($row), $row['amounts'], $row['sum']);
                }

                $rows[] = $line($group['label'], $group['totals'], $group['sum']);

                if ($group['subtotal'] !== null) {
                    $rows[] = $line($group['subtotal']['label'], $group['subtotal']['amounts'], $group['subtotal']['sum']);
                }
            }

            $rows[] = $line(__('Årets resultat'), $report['income']['result']['amounts'], $report['income']['result']['sum']);

            return $rows;
        }

        foreach ($report['balance']['sides'] as $side) {
            foreach ($side['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $rows[] = $line(GroupOverview::rowLabel($row), $row['amounts'], $row['sum']);
                }

                $rows[] = $line($group['label'], $group['totals'], $group['sum']);
            }

            if (isset($side['unbooked']) && $side['unbooked']['sum'] !== 0) {
                $rows[] = $line($side['unbooked']['label'], $side['unbooked']['amounts'], $side['unbooked']['sum']);
            }

            $rows[] = $line($side['label'], $side['totals'], $side['sum']);
        }

        return $rows;
    }
}
