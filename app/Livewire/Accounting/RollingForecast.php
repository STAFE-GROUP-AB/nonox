<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\RollingTwelveMonths;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Rullande 12 månader" (ADR-081 gap 2 scope 5): the next twelve months
 * across a fiscal year boundary, each month from its year's active forecast,
 * else its active budget. The first month is kept in the URL (AGENTS.md
 * §11.3). Six queries, whatever the number of versions or lines.
 */
class RollingForecast extends Component
{
    #[Url(except: '')]
    public string $fran = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [BudgetVersion::class, $this->company()]), 403);
    }

    public function previous(): void
    {
        $this->fran = BudgetMonths::shift($this->from(), -1);
    }

    public function next(): void
    {
        $this->fran = BudgetMonths::shift($this->from(), 1);
    }

    public function render(): View
    {
        $from = $this->from();
        $this->fran = $from;

        return view('livewire.accounting.rolling-forecast', [
            'report' => RollingTwelveMonths::build($from),
        ]);
    }

    /**
     * The chosen month; else the month after the cut-off of this year's
     * active forecast, unless that forecast is behind the books; else the
     * current month.
     */
    private function from(): string
    {
        $chosen = substr($this->fran, 0, 7);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $chosen) === 1 && $chosen >= '1900-01' && $chosen <= '2999-12') {
            return $chosen;
        }

        $today = CarbonImmutable::now();
        $year = FiscalYear::query()->whereDate('start_date', '<=', $today->toDateString())->whereDate('end_date', '>=', $today->toDateString())->first();
        $forecast = $year === null ? null : BudgetVersion::query()
            ->where('fiscal_year_id', $year->id)
            ->where('kind', BudgetKind::Forecast->value)
            ->where('is_active', true)
            ->first(['id', 'forecast_cutoff']);

        // A forecast more than a month behind the books does not decide where the twelve months start.
        if ($year === null || $forecast === null || $forecast->forecast_cutoff === null || RollingTwelveMonths::stale($forecast, $year)) {
            return $today->format('Y-m');
        }

        return BudgetMonths::shift($forecast->forecast_cutoff->format('Y-m'), 1);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
