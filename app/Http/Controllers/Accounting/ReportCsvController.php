<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\Reports\ReportFilters;
use App\Domain\Accounting\Reports\ReportTable;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The report on the screen as a CSV file that Excel opens directly: UTF-8
 * with a byte order mark, semicolons, decimal commas.
 */
class ReportCsvController
{
    public function __invoke(Request $request, ReportTable $table): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->hasTeamPermission($user->currentCompany(), 'reports:view'), 403);

        $filters = ReportFilters::fromInput($request->query());
        abort_if($filters === null, 404);

        $rows = $table->rows($filters->report, $filters->query(), $filters->account, $filters->compare);
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
}
