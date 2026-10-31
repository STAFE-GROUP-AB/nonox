<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\StaffLedger\Actions\OpenControlVisit;
use App\Modules\StaffLedger\Support\Export\ControlVisitReport;
use App\Modules\StaffLedger\Support\Export\CsvExport;
use App\Modules\StaffLedger\Support\Export\LedgerXmlFormat;
use App\Modules\StaffLedger\Support\Export\PdfExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The control visit exports: PDF, CSV and XML for the chosen period,
 * location and search. Each export is written to the audit log, since it
 * carries personal numbers in full.
 */
class ControlVisitExportController
{
    public function __invoke(Request $request, string $format, OpenControlVisit $open, LedgerXmlFormat $xml): Response
    {
        /** @var User $user */
        $user = Auth::user();
        /** @var Company $company */
        $company = $user->currentCompany();

        $open->inApp($user, $company, $format);

        [$from, $to] = self::period($request);
        $location = $request->integer('lokal') ?: null;
        $report = ControlVisitReport::build($company, $location, $from, $to, (string) $request->query('sok', ''));

        [$body, $type] = match ($format) {
            'pdf' => [PdfExport::render($report), 'application/pdf'],
            'csv' => [CsvExport::render($report), 'text/csv; charset=utf-8'],
            default => [$xml->render($report), 'application/xml; charset=utf-8'],
        };

        return response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$report->filename($format).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function period(Request $request): array
    {
        $parse = function (mixed $value, CarbonImmutable $default): CarbonImmutable {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return $default;
            }

            return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: $default;
        };

        $from = $parse($request->query('fran'), CarbonImmutable::today()->subDays(6))->startOfDay();
        $to = $parse($request->query('till'), CarbonImmutable::today())->endOfDay();

        return $to < $from ? [$to->startOfDay(), $from->endOfDay()] : [$from, $to];
    }
}
