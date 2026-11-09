<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Modules\Saas\Domain\Arithmetic;
use App\Modules\Saas\Domain\Months;
use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\SaasSettings;
use App\Modules\Saas\Support\SaasReport;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Återkommande intäkter → Översikt (ADR-080 §4, screen 1): the month's MRR,
 * ARR, customers, ARPA, churn, NRR and GRR, the bridge from last month, and
 * 24 months of MRR with the switch "Som rapporterat / Med rättelser". Reads
 * the stored ledger only: five queries whatever the number of customers.
 */
class OverviewPage extends Component
{
    use InSaas;

    /** Months in the chart. */
    public const int CHART_MONTHS = 24;

    #[Url(as: 'manad', except: '')]
    public string $month = '';

    #[Url(as: 'visa', except: 'rapporterat')]
    public string $show = 'rapporterat';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(): View
    {
        $months = SaasReport::months();
        $selected = SaasReport::pick($months, $this->month);
        $withCorrections = $this->show === 'med-rattelser';
        $adjusted = $withCorrections ? SaasReport::withCorrections($months) : [];
        $estimates = SaasReport::estimates();
        $chart = [];

        foreach ($estimates as $key => $estimate) {
            $chart[$key] = ['mrr_ore' => $estimate['mrr_ore'], 'kind' => 'estimate', 'closed' => false, 'corrected' => false];
        }

        foreach ($months as $key => $month) {
            $value = $withCorrections ? ($adjusted[$key] ?? $month->mrr_ore) : $month->mrr_ore;
            $chart[$key] = ['mrr_ore' => $value, 'kind' => 'ledger', 'closed' => $month->isClosed(), 'corrected' => $value !== $month->mrr_ore];
        }

        ksort($chart);
        $chart = array_slice($chart, -self::CHART_MONTHS, null, true);
        $latest = $months->last();

        return view('saas::livewire.overview', [
            'months' => $months,
            'selected' => $selected,
            'hasData' => $months->contains(fn (SaasMonth $month): bool => $month->mrr_ore !== 0 || $month->opening_mrr_ore !== 0 || $month->correction_ore !== 0) || $estimates !== [],
            'previous' => $selected === null ? null : $months->get(Months::add($selected->key(), -1)),
            'chart' => $chart,
            'chartMax' => max(1, ...array_values(array_map(fn (array $point): int => $point['mrr_ore'], $chart ?: [['mrr_ore' => 1]]))),
            'withCorrections' => $withCorrections,
            'adjusted' => $adjusted,
            'historyFrom' => SaasSettings::historyFrom($this->company()),
            'issues' => $latest?->source_issues ?? 0,
            'rates' => $selected === null ? [] : self::rates($selected),
        ]);
    }

    /**
     * The month's rates as the page shows them (SA-R17, SA-R18).
     *
     * @return array<string, ?string>
     */
    private static function rates(SaasMonth $month): array
    {
        $lost = -($month->contraction_ore + $month->churn_from_active_ore);

        return [
            'logo' => Arithmetic::percent($month->churned_customers, $month->opening_customers),
            'gross' => Arithmetic::percent($lost, $month->opening_mrr_ore),
            'net' => Arithmetic::percent($lost - $month->expansion_ore, $month->opening_mrr_ore),
            'nrr' => Arithmetic::percent($month->nrr_ore, $month->nrr_base_ore),
            'grr' => Arithmetic::percent($month->grr_ore, $month->nrr_base_ore),
        ];
    }
}
