<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Actions\Planning\GenerateDemandForecast;
use App\Modules\Inventory\Actions\Planning\SaveDemandForecast;
use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\DemandForecast;
use App\Modules\Inventory\Planning\DemandHistory;
use App\Modules\Inventory\Planning\ForecastPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Lager → Prognos (ADR-081 gap 5): the expected demand per stocked article
 * and week or month, the base of the MRP engine. Each cell is typed by hand
 * (and saved when the field is left) or computed from the stock history for
 * all articles in the selection; the cell shows which. The horizon is 13
 * weeks by default and at most 52 weeks (12 months). A fixed number of
 * queries for the page whatever the number of articles: the articles of the
 * page, their forecasts and their history.
 */
class ForecastPage extends Component
{
    use InventoryPage;
    use WithPagination;

    public const string NAME = 'inventory.forecast';

    private const int PER_PAGE = 25;

    #[Url(as: 'period')]
    public string $kind = 'vecka';

    #[Url(as: 'perioder')]
    public string $periods = '13';

    #[Url(as: 'sok')]
    public string $search = '';

    #[Url(as: 'historik')]
    public string $months = '3';

    public bool $overwriteManual = false;

    public function mount(): void
    {
        abort_unless($this->can('planView'), 403);

        $this->periods = (string) $this->clampPeriods($this->periods);
        $this->months = (string) $this->clampMonths($this->months);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'kind', 'periods'], true)) {
            $this->resetPage();
        }
    }

    public function updatedKind(): void
    {
        $this->periods = (string) $this->kindEnum()->defaultCount();
    }

    public function updatedPeriods(): void
    {
        $this->periods = (string) $this->clampPeriods($this->periods);
    }

    public function updatedMonths(): void
    {
        $this->months = (string) $this->clampMonths($this->months);
    }

    /** Saves the figure typed in one cell, or clears it when the cell is emptied. */
    public function setCell(int $articleId, string $start, string $value, SaveDemandForecast $save): void
    {
        abort_unless($this->can('planRun'), 403);

        $article = Article::query()->findOrFail($articleId);

        $date = null;

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $start) ?: null;
        } catch (Throwable) {
        }

        if ($date === null) {
            $this->toast(__('Perioden går inte att läsa.'), 'error');

            return;
        }

        try {
            $forecast = $save($this->user(), $this->company(), $article, $this->kindEnum(), $date, $value);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($forecast === null ? __('Prognosen är borttagen.') : __('Prognosen är sparad.'));
    }

    /** Computes the forecast of every article in the selection from the stock history. */
    public function generate(GenerateDemandForecast $generate): void
    {
        abort_unless($this->can('planRun'), 403);

        $ids = array_values($this->selection()->limit(GenerateDemandForecast::MAX_ARTICLES + 1)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        try {
            $result = $generate($this->user(), $this->company(), $ids, $this->kindEnum(), $this->clampPeriods($this->periods), $this->clampMonths($this->months), $this->overwriteManual);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Prognosen är beräknad för :articles artiklar: :written värden skrivna och :kept egna värden behölls.', [
            'articles' => $result['articles'],
            'written' => $result['written'],
            'kept' => $result['kept_manual'],
        ]));
    }

    public function render(): View
    {
        $kind = $this->kindEnum();
        $today = CarbonImmutable::today();
        $periods = ForecastPeriod::horizon($kind, $today, $this->clampPeriods($this->periods));
        $window = DemandHistory::window($today, $this->clampMonths($this->months));

        $articles = $this->selection()
            ->with('stockUnit:id,code')
            ->orderBy('number')
            ->paginate(self::PER_PAGE);

        $ids = array_values($articles->getCollection()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $forecasts = [];

        if ($ids !== []) {
            foreach (DemandForecast::query()->whereIn('article_id', $ids)
                ->where('period_kind', $kind->value)
                ->whereBetween('period_start', [$periods[0]->start->toDateString(), $periods[count($periods) - 1]->start->toDateString()])
                ->get(['article_id', 'period_start', 'quantity_milli', 'source']) as $row) {
                $forecasts[$row->article_id][$row->period_start->toDateString()] = $row;
            }
        }

        $consumption = $window->consumption($ids);

        return view('inventory::livewire.forecast', [
            'articles' => $articles,
            'columns' => $periods,
            'kindEnum' => $kind,
            'forecasts' => $forecasts,
            'window' => $window,
            'perPeriod' => array_map(fn (int $consumed): int => $kind === ForecastPeriodKind::Week ? $window->perWeek($consumed) : $window->perMonth($consumed), $consumption),
            'canRun' => $this->can('planRun'),
            'today' => $today,
        ]);
    }

    /** @return Builder<Article> the stocked articles that match the search */
    private function selection(): Builder
    {
        $term = trim($this->search);

        return Article::query()
            ->where('is_stock_item', true)
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('number', 'ilike', '%'.$term.'%')
                ->orWhere('name', 'ilike', '%'.$term.'%')));
    }

    private function kindEnum(): ForecastPeriodKind
    {
        return $this->kind === 'manad' ? ForecastPeriodKind::Month : ForecastPeriodKind::Week;
    }

    private function clampPeriods(string $periods): int
    {
        return max(1, min((int) $periods, $this->kindEnum()->maxCount()));
    }

    private function clampMonths(string $months): int
    {
        return max(1, min((int) $months, DemandHistory::MAX_MONTHS));
    }
}
