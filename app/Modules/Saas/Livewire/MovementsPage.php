<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\SaasSettings;
use App\Modules\Saas\Support\SaasReport;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Återkommande intäkter → Rörelser (screen 2): months as rows, the bridge's
 * categories as columns; each cell opens the customers behind it. One query.
 */
class MovementsPage extends Component
{
    use InSaas;

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(): View
    {
        return view('saas::livewire.movements', [
            'months' => SaasReport::months()->reverse(),
            'historyFrom' => SaasSettings::historyFrom($this->company()),
        ]);
    }
}
