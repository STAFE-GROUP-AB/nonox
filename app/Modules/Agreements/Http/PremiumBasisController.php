<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Support\PremiumBasis;
use App\Support\Audit\Audit;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the Fora or Collectum basis for a payment month as CSV
 * (PremiumBasis, columns documented in docs/avtal/datamodell.md §6.7). The
 * file holds personal numbers: it needs agreements:manage and every
 * download is written to the audit log.
 */
class PremiumBasisController
{
    public function __invoke(Request $request, string $rapport): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();
        $report = match ($rapport) {
            'fora' => PremiumBasis::FORA,
            'collectum' => PremiumBasis::COLLECTUM,
            default => abort(404),
        };
        $month = (string) $request->query('manad', '');

        abort_unless($user->hasTeamPermission($company, 'agreements:manage'), 403);
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1, 404);

        $rows = PremiumBasis::rows($month, $report);
        Audit::record('agreements.premium_basis_downloaded', null, ['report' => $report, 'month' => $month, 'rows' => count($rows)], $company, $user);

        return response()->streamDownload(function () use ($rows, $report): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            foreach (PremiumBasis::csv($rows, $report) as $line) {
                fputcsv($out, Csv::row($line), ';', '"', '');
            }

            fclose($out);
        }, "{$rapport}-underlag-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
