<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Livewire;

use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Livewire\Concerns\KitchenPage;
use App\Modules\Kitchen\Support\MenuReport;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kök → Meny (ADR-073 §4): every dish with portion cost, net menu price,
 * contribution and food cost %, and from the till in the chosen period the
 * portions sold, the realised average price and the contribution earned.
 * Sortable; dishes above the company's target food cost are marked. NONOX
 * never changes a price. Costs only for those who may see them.
 */
class MenuPage extends Component
{
    use KitchenPage;

    public const array SORTS = ['namn', 'matkostnad', 'bidrag', 'salda', 'intjanat'];

    #[Url(as: 'fran')]
    public string $from = '';

    #[Url(as: 'till')]
    public string $to = '';

    #[Url(as: 'sortera')]
    public string $sort = 'namn';

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
    }

    public function render(): View
    {
        $seesCosts = $this->can('seeCosts');
        $to = self::date($this->to) ?? CarbonImmutable::today();
        $from = self::date($this->from) ?? $to->subDays(29);
        $rows = MenuReport::for($this->company(), $from, $to);

        // Largest first; a dish without a figure last; then by name.
        $key = match ($seesCosts ? $this->sort : ($this->sort === 'salda' ? 'salda' : 'namn')) {
            'matkostnad' => fn (array $row): ?int => $row['food_cost_bp'],
            'bidrag' => fn (array $row): ?int => $row['contribution_ore'],
            'salda' => fn (array $row): ?int => $row['sold_milli'],
            'intjanat' => fn (array $row): ?int => $row['earned_ore'],
            default => null,
        };

        if ($key !== null) {
            usort($rows, fn (array $a, array $b): int => [$key($b) ?? PHP_INT_MIN, $a['name']] <=> [$key($a) ?? PHP_INT_MIN, $b['name']]);
        }

        return view('kitchen::livewire.menu', [
            'rows' => $rows,
            'seesCosts' => $seesCosts,
            'fromDate' => $from,
            'toDate' => $to,
            'target' => KitchenSettings::for($this->company())['target_food_cost_bp'],
        ]);
    }

    private static function date(string $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? CarbonImmutable::parse($value) : null;
    }
}
