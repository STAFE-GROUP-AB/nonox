<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Accounting\ExportSie;
use App\Models\FiscalYear;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A fiscal year's months and whether they are locked, and the year as a
 * SIE 4 file (ADR-032). The lock is the company's: everything up to and
 * including locked_until_date is closed for posting (AGENTS.md §1.2).
 */
final class FiscalYearController
{
    use ActsAsCredential;

    public function periods(FiscalYear $fiscalYear): JsonResponse
    {
        $lockedUntil = $this->company()->locked_until_date;
        $periods = [];

        for ($start = $fiscalYear->start_date; $start->lte($fiscalYear->end_date); $start = $start->addMonthNoOverflow()->startOfMonth()) {
            $end = $start->endOfMonth()->startOfDay()->min($fiscalYear->end_date);

            $periods[] = [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'locked' => $lockedUntil !== null && $end->lte($lockedUntil),
                'partly_locked' => $lockedUntil !== null && $start->lte($lockedUntil) && $end->gt($lockedUntil),
            ];
        }

        return new JsonResponse(['data' => [
            'fiscal_year_id' => $fiscalYear->id,
            'status' => $fiscalYear->status->value,
            'locked_until_date' => $lockedUntil?->toDateString(),
            'periods' => $periods,
        ]], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The SIE 4 file of the year, in its own encoding (CP437), with every
     * posted voucher. It is written in full while the request's credential
     * and company still apply, then sent.
     */
    public function sie(FiscalYear $fiscalYear, ExportSie $export): StreamedResponse
    {
        Gate::forUser($this->user())->authorize('exportSie', $fiscalYear);

        $file = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b') ?: throw new RuntimeException('Could not open a temporary stream.');
        $export($this->user(), $fiscalYear, $file);
        rewind($file);

        return new StreamedResponse(function () use ($file): void {
            fpassthru($file);
            fclose($file);
        }, 200, [
            'Content-Type' => 'text/plain; charset=IBM437',
            'Content-Disposition' => 'attachment; filename="'.ExportSie::filename($this->company(), $fiscalYear).'"',
        ]);
    }
}
