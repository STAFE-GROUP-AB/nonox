<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Models\SaasCorrection;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Återkommande intäkter → Rättelser (screen 6, SA-R22): what became known
 * after a month was closed, per month it was booked in and the month it
 * affects. The closed month itself never changes. Two queries.
 */
class CorrectionsPage extends Component
{
    use InSaas;
    use WithPagination;

    public const int PER_PAGE = 50;

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(): View
    {
        return view('saas::livewire.corrections', [
            'corrections' => SaasCorrection::query()
                ->with('customer:id,number,name')
                ->orderByDesc('booked_month')
                ->orderBy('affects_month')
                ->orderBy('id')
                ->paginate(self::PER_PAGE),
        ]);
    }
}
