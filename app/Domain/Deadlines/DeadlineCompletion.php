<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use App\Models\Company;
use App\Models\VatReport;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Support\Tenancy\CurrentCompany;

/**
 * Which of the company's dates are already done (ADR-051, stage 2), as the
 * existing items in Att göra know it: a VAT period with a VAT report, an
 * employer declaration with a submitted or accepted file (or a correction
 * of one). A done date gets no reminder and is shown as reported. Two
 * queries.
 */
final class DeadlineCompletion
{
    /**
     * @param  list<Deadline>  $deadlines
     * @return list<string> the keys of the deadlines that are done
     */
    public static function done(Company $company, array $deadlines): array
    {
        $vat = array_filter($deadlines, fn (Deadline $deadline): bool => $deadline->type === DeadlineType::Vat && $deadline->periodStart !== null);
        // Only the declaration: a filed declaration does not show that a large employer has paid (agi-payment:…).
        $agi = array_filter($deadlines, fn (Deadline $deadline): bool => $deadline->type === DeadlineType::Agi && str_starts_with($deadline->key, 'agi:') && $deadline->periodStart !== null);
        $done = [];

        CurrentCompany::run($company, function () use ($vat, $agi, &$done): void {
            if ($vat !== []) {
                $reported = VatReport::query()->whereIn('period_start', array_map(fn (Deadline $deadline): string => (string) $deadline->periodStart?->toDateString(), $vat))
                    ->pluck('period_start')->map(fn (mixed $date): string => substr((string) $date, 0, 10))->all();

                foreach ($vat as $deadline) {
                    if (in_array($deadline->periodStart?->toDateString(), $reported, true)) {
                        $done[] = $deadline->key;
                    }
                }
            }

            if ($agi !== [] && app(ModuleRegistry::class)->has('payroll')) {
                $periods = AgiSubmission::query()
                    ->whereIn('period', array_map(fn (Deadline $deadline): string => (string) $deadline->periodStart?->format('Y-m'), $agi))
                    ->whereIn('status', [...AgiSubmission::REPORTED, AgiSubmission::CORRECTED])
                    ->pluck('period')->map(fn (mixed $period): string => (string) $period)->all();

                foreach ($agi as $deadline) {
                    if (in_array($deadline->periodStart?->format('Y-m'), $periods, true)) {
                        $done[] = $deadline->key;
                    }
                }
            }
        });

        return $done;
    }
}
