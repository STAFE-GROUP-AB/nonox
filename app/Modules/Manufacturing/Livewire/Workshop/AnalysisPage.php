<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Workshop;

use App\Modules\Manufacturing\Livewire\Concerns\ManufacturingPage;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Support\WorkshopAnalysis;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tillverkning → Efterkalkyl (ADR-071 §7, production:analyse): calculated
 * against reported minutes per product and operation, the bottleneck
 * operations, and what open orders wait for. Per product and operation
 * only; there is no view per person.
 */
class AnalysisPage extends Component
{
    use ManufacturingPage;

    #[Url(as: 'period')]
    public int $months = 12;

    public function mount(): void
    {
        abort_unless(Gate::forUser($this->user())->allows('analyse', [Operation::class, $this->company()]), 403);
    }

    public function render(): View
    {
        return view('manufacturing::livewire.analysis', [
            'analysis' => WorkshopAnalysis::for($this->months),
            'periods' => WorkshopAnalysis::PERIODS,
        ]);
    }
}
