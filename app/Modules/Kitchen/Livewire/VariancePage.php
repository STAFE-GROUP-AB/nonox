<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Livewire;

use App\Modules\Inventory\Domain\StockClass;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Kitchen\Actions\ConsumeSales;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Livewire\Concerns\KitchenPage;
use App\Modules\Kitchen\Models\ConsumptionWait;
use App\Modules\Kitchen\Support\VarianceReport;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kök → Avvikelser (ADR-073 §8): the variance between two stock counts,
 * per ingredient and stock class, largest value first, with what was sold
 * without a recipe and waste registered after the count. With drawing on
 * sale on, also the ingredients waiting for stock. For kitchen:manage.
 */
class VariancePage extends Component
{
    use KitchenPage;

    #[Url(as: 'fran')]
    public ?int $openingId = null;

    #[Url(as: 'till')]
    public ?int $closingId = null;

    public function mount(): void
    {
        abort_unless($this->can('manage'), 403);

        if ($this->openingId === null || $this->closingId === null) {
            $latest = StockCount::query()->where('status', StockCount::COMPLETED)->orderByDesc('counted_on')->orderByDesc('id')->limit(2)->pluck('id')->all();
            $this->closingId ??= $latest[0] ?? null;
            $this->openingId ??= $latest[1] ?? null;
        }
    }

    public function resolve(int $waitId, bool $post, ConsumeSales $consume): void
    {
        abort_unless($this->can('manage'), 403);
        $wait = ConsumptionWait::query()->findOrFail($waitId);

        $this->attempt(fn () => $consume->resolve($this->user(), $this->company(), $wait, $post), $post ? __('Ingrediensen är dragen från lagret.') : __('Ingrediensen lämnas till nästa inventering.'), 'wait.');
    }

    public function render(): View
    {
        $counts = StockCount::query()->where('status', StockCount::COMPLETED)->with('location:id,full_code')->orderByDesc('counted_on')->orderByDesc('id')->get(['id', 'number', 'counted_on', 'location_id', 'status', 'completed_at']);
        $opening = $counts->firstWhere('id', $this->openingId);
        $closing = $counts->firstWhere('id', $this->closingId);
        $report = null;
        $error = null;

        if ($opening instanceof StockCount && $closing instanceof StockCount) {
            try {
                $report = VarianceReport::between($this->company(), $opening, $closing);
            } catch (ValidationException $exception) {
                $error = $this->firstError($exception);
            }
        }

        return view('kitchen::livewire.variance', [
            'counts' => $counts,
            'report' => $report,
            'error' => $error,
            'classes' => StockClass::cases(),
            'drawsOnSale' => KitchenSettings::drawsOnSale($this->company()),
            'waits' => ConsumptionWait::query()->where('status', ConsumptionWait::WAITING)->with(['article:id,name,unit', 'location:id,full_code'])->orderBy('business_date')->get(),
        ]);
    }
}
