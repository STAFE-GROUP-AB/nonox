<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Support\SaasReport;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Återkommande intäkter → Kohorter (screen 4, SA-R19): per cohort (the month
 * of the first MRR) and month since start, the share of the cohort's
 * customers with MRR, or its MRR against month 0 at the selected month's
 * rates. Read from the month's stored cohort table: one query.
 */
class CohortsPage extends Component
{
    use InSaas;

    #[Url(as: 'manad', except: '')]
    public string $month = '';

    #[Url(as: 'visa', except: 'kunder')]
    public string $show = 'kunder';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(): View
    {
        $months = SaasReport::months();
        $selected = SaasReport::pick($months, $this->month);

        return view('saas::livewire.cohorts', [
            'months' => $months,
            'selected' => $selected,
            'cohorts' => (array) $selected?->cohorts,
            'revenue' => $this->show === 'intakt',
        ]);
    }
}
