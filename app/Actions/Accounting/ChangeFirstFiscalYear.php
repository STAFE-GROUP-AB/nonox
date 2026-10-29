<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\FiscalYearRules;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Adjusts the dates of a company's first fiscal year, for example when the
 * company was registered in the middle of a year. Only possible while it is
 * the company's only fiscal year and it is open.
 *
 * TODO(T-1.2): also refuse once the year has posted vouchers.
 */
class ChangeFirstFiscalYear
{
    public function __invoke(User $user, FiscalYear $fiscalYear, string $startDate, string $endDate): void
    {
        Gate::forUser($user)->authorize('update', $fiscalYear);

        $start = $this->date($startDate, 'start_date');
        $end = $this->date($endDate, 'end_date');

        CurrentCompany::run($fiscalYear->company_id, function () use ($fiscalYear, $start, $end, $user): void {
            if (! $fiscalYear->isOpen() || FiscalYear::query()->whereKeyNot($fiscalYear->id)->exists()) {
                throw ValidationException::withMessages([
                    'start_date' => __('Bara företagets första räkenskapsår kan ändras, och bara så länge det är öppet.'),
                ]);
            }

            $problem = FiscalYearRules::problem($start, $end, mayDeviate: true);

            if ($problem !== null) {
                throw ValidationException::withMessages(['start_date' => $problem]);
            }

            $before = ['start_date' => $fiscalYear->start_date->toDateString(), 'end_date' => $fiscalYear->end_date->toDateString()];

            $fiscalYear->update(['start_date' => $start, 'end_date' => $end]);

            Audit::record('fiscal_year.changed', $fiscalYear, Audit::changes($before, [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ]), $fiscalYear->company_id, $user);
        });
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay()
                ?? throw new \InvalidArgumentException;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('Datumet ska skrivas som ÅÅÅÅ-MM-DD.')]);
        }
    }
}
