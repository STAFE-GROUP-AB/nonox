<?php

declare(strict_types=1);

namespace App\Livewire\Overview;

use App\Domain\Analysis\AnalysisRunner;
use App\Domain\Analysis\Measure;
use App\Domain\Analysis\Reports\SavedReports;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Saved reports pinned to the overview as tiles (ADR-081 gap 7, E18): the
 * total of each report, run now with the viewer's own permissions and
 * dimension scope, so a company report shows each person only what they may
 * see (a small payroll group is "Dold", as in the analysis view).
 *
 * Lazy, so the overview itself runs no extra query; at most
 * SavedReports::MAX_TILES tiles, so this component runs a fixed number of
 * queries whatever the number of saved reports: one for the reports and, per
 * tile, the grouped query of the analysis.
 */
#[Lazy]
class ReportTiles extends Component
{
    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function render(AnalysisRunner $runner): View
    {
        /** @var User $user */
        $user = Auth::user();
        $company = $user->currentCompany();
        $tiles = [];

        if (! $company instanceof Company) {
            return view('livewire.overview.report-tiles', ['tiles' => $tiles]);
        }

        foreach (SavedReports::pinnedFor($user, $company) as $report) {
            $run = SavedReports::run($report, $user, $company, $runner);

            if ($run === null) {
                continue;
            }

            $total = $run['result']->grandTotal;
            $money = $run['dataset']->measures()[$run['definition']->measure]->kind === Measure::MONEY;

            $tiles[] = [
                'id' => $report->id,
                'name' => $report->name,
                'money' => $money,
                'value' => $total['hidden'] ? 0 : $total['v'],
                'previous' => $run['definition']->compare && ! $total['hiddenPrevious'] ? $total['p'] : null,
                'hidden' => $total['hidden'],
                'tooLarge' => $run['result']->tooLarge,
                'period' => $run['definition']->from->format('Y-m').' – '.$run['definition']->to->format('Y-m'),
            ];
        }

        return view('livewire.overview.report-tiles', ['tiles' => $tiles]);
    }
}
