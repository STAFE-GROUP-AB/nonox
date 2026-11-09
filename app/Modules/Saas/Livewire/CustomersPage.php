<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Support\SaasReport;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Återkommande intäkter → Kunder (screen 3): every customer of the month with
 * MRR, the month's change, cohort, sources and state, largest MRR first, a
 * page at a time. Three queries.
 */
class CustomersPage extends Component
{
    use InSaas;
    use WithPagination;

    public const int PER_PAGE = 50;

    #[Url(as: 'manad', except: '')]
    public string $month = '';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function updatedMonth(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $months = SaasReport::months();
        $selected = SaasReport::pick($months, $this->month);
        $rows = $selected === null ? null : SaasCustomerMonth::query()
            ->with('customer:id,number,name')
            ->where('month', $selected->key())
            ->orderByDesc('mrr_ore')
            ->orderBy('customer_id')
            ->paginate(self::PER_PAGE);

        return view('saas::livewire.customers', [
            'months' => $months,
            'selected' => $selected,
            'rows' => $rows,
        ]);
    }
}
