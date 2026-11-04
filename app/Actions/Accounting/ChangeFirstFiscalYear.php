<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\FiscalYearRules;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Adjusts the dates of a company's first fiscal year, for example when the
 * company was registered in the middle of a year (BFL 3 kap. 3 §). Only
 * possible while it is open and the company's only year, with one exception:
 * a following year that NONOX created by itself (CreateNextFiscalYear, audit
 * `via: automatic`) and that holds nothing (no vouchers, drafts included,
 * no opening balances) is no obstacle. It is removed in the same
 * transaction (`fiscal_year.removed`), and the next ordinary year is then
 * created again by the usual rules when it is due.
 *
 * TODO(T-1.2): also refuse once the year has posted vouchers.
 */
class ChangeFirstFiscalYear
{
    public function __construct(private readonly CreateNextFiscalYear $createNextFiscalYear) {}

    public function __invoke(User $user, FiscalYear $fiscalYear, string $startDate, string $endDate): void
    {
        Gate::forUser($user)->authorize('update', $fiscalYear);

        $start = $this->date($startDate, 'start_date');
        $end = $this->date($endDate, 'end_date');

        CurrentCompany::run($fiscalYear->company_id, fn () => DB::transaction(function () use ($fiscalYear, $start, $end, $user): void {
            // The year row first, then the creation lock: the same order as closing a year (YearEndClosing::close
            // locks the year, then CarryForwardBalances takes the creation lock), so the two never wait on each other.
            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();
            CreateNextFiscalYear::lock($fiscalYear->company_id);
            $company = Company::query()->findOrFail($fiscalYear->company_id);
            $others = FiscalYear::query()->whereKeyNot($fiscalYear->id)->get();

            if (! $fiscalYear->isOpen() || $others->contains(fn (FiscalYear $other): bool => ! $this->removable($other, $fiscalYear))) {
                throw ValidationException::withMessages([
                    'start_date' => __('Bara företagets första räkenskapsår kan ändras, och bara så länge det är öppet.'),
                ]);
            }

            $problem = FiscalYearRules::problem($start, $end, mayDeviate: true, calendarYear: FiscalYearRules::requiresCalendarYear($company->legal_form));

            if ($problem !== null) {
                throw ValidationException::withMessages(['start_date' => $problem]);
            }

            foreach ($others as $other) {
                $this->remove($other, $fiscalYear, $company, $user);
            }

            $before = ['start_date' => $fiscalYear->start_date->toDateString(), 'end_date' => $fiscalYear->end_date->toDateString()];

            $fiscalYear->update(['start_date' => $start, 'end_date' => $end]);

            Audit::record('fiscal_year.changed', $fiscalYear, Audit::changes($before, [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ]), $fiscalYear->company_id, $user);

            if ($others->isNotEmpty()) {
                $this->createNextFiscalYear->dueFor($company, CarbonImmutable::today());
            }
        }));
    }

    /**
     * Whether the first year's dates may still change: it is open, and every
     * other year is one that removable() accepts. For the page's "Ändra
     * datum"; run in the company's scope.
     */
    public function changeable(FiscalYear $first): bool
    {
        if (! $first->isOpen()) {
            return false;
        }

        $others = FiscalYear::query()->whereKeyNot($first->id)->get();

        return $others->every(fn (FiscalYear $other): bool => $this->removable($other, $first));
    }

    /**
     * A year after the first one that NONOX created by itself and that
     * nothing has been entered in yet.
     */
    private function removable(FiscalYear $other, FiscalYear $first): bool
    {
        return $other->start_date->isAfter($first->end_date)
            && $other->isOpen()
            && AuditLog::query()
                ->where('company_id', $other->company_id)
                ->where('action', 'fiscal_year.created')
                ->where('subject_type', $other->getMorphClass())
                ->where('subject_id', (string) $other->id)
                ->where('payload->via', 'automatic')
                ->exists()
            && ! Voucher::query()->where('fiscal_year_id', $other->id)->exists()
            && ! OpeningBalance::query()->where('fiscal_year_id', $other->id)->exists();
    }

    private function remove(FiscalYear $other, FiscalYear $first, Company $company, User $user): void
    {
        Audit::record('fiscal_year.removed', $other, [
            'fiscal_year_id' => $other->id,
            'first_year_id' => $first->id,
            'start_date' => $other->start_date->toDateString(),
            'end_date' => $other->end_date->toDateString(),
            'reason' => 'first_year_changed',
        ], $company, $user);

        try {
            // A savepoint: anything else that refers to the year (an archive package, a bank reconciliation) stops the removal.
            DB::transaction(fn () => $other->delete());
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'start_date' => __('Bara företagets första räkenskapsår kan ändras, och bara så länge det är öppet.'),
            ]);
        }
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
