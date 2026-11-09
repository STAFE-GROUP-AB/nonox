<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Support\SaasReport;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Återkommande intäkter → Källor (screen 9): what could not be counted and
 * why — agreements without a customer, CRM agreements that cannot be spread
 * per month, and currencies without a rate — next to the notes that explain
 * what is left out on purpose. Three queries.
 */
class SourcesPage extends Component
{
    use InSaas;

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(): View
    {
        $latest = SaasReport::months()->last();

        return view('saas::livewire.sources', [
            'uncounted' => SaasReport::uncounted(),
            'missingRates' => (array) $latest?->missing_rates,
            'latest' => $latest,
        ]);
    }
}
