<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vat;

use App\Domain\Vat\EskdFile;
use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The momsdeklaration as a file for Skatteverket's e-service.
 */
class VatFileController
{
    public function __invoke(Request $request, FiscalYear $fiscalYear): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company $company */
        $company = $user->currentCompany();

        abort_unless($user->hasTeamPermission($company, 'vat:prepare'), 403);

        $period = collect(VatPeriods::of($fiscalYear, $company->vat_period))
            ->first(fn (array $period): bool => $period['start']->toDateString() === (string) $request->query('period'));

        abort_if($period === null, 404);

        $return = VatReturn::calculate($fiscalYear, $period['start'], $period['end']);

        Audit::record('vat.file_downloaded', $fiscalYear, [
            'period' => "{$period['start']->toDateString()} – {$period['end']->toDateString()}",
            'payable' => $return->payable(),
        ], $company, $user);

        return response((string) mb_convert_encoding(EskdFile::render($company, $return), 'ISO-8859-1', 'UTF-8'), 200, [
            'Content-Type' => 'application/xml; charset=ISO-8859-1',
            'Content-Disposition' => 'attachment; filename="'.EskdFile::filename($company, $return).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
