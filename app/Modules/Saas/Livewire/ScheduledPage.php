<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Support\SaasReport;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Återkommande intäkter → Uppsagda (screen 5, SA-R15): agreements that are
 * terminated or end on a date but still run. Their MRR stays until the end
 * of the last paid period and is churn only then. Two queries.
 */
class ScheduledPage extends Component
{
    use InSaas;

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(): View
    {
        $latest = SaasReport::months()->last();

        return view('saas::livewire.scheduled', [
            'latest' => $latest,
            'contracts' => $latest === null ? [] : SaasReport::scheduled($latest->key()),
        ]);
    }
}
