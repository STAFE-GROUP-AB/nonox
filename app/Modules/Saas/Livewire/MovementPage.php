<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\Support\SaasReport;
use App\Modules\Saas\Support\SourceLabels;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The customers and documents behind one figure of a month (SA-R21): every
 * customer's amount in the column, which together add up to the figure to
 * the öre, with the contract lines behind each. Two queries.
 */
class MovementPage extends Component
{
    use InSaas;

    public const array EXTRA = ['ingaende', 'utgaende'];

    #[Locked]
    public string $month = '';

    #[Url(as: 'kategori', except: 'utgaende')]
    public string $category = 'utgaende';

    public function mount(string $month): void
    {
        $this->authorizeView();
        $this->month = $month.'-01';
        abort_unless(SaasMonth::query()->where('month', $this->month)->exists(), 404);

        if (! array_key_exists($this->category, SaasReport::CATEGORIES) && ! in_array($this->category, self::EXTRA, true)) {
            $this->category = 'utgaende';
        }
    }

    public function render(): View
    {
        $month = SaasMonth::query()->where('month', $this->month)->firstOrFail();
        $rows = SaasReport::drill($this->month, $this->category);

        return view('saas::livewire.movement', [
            'monthRow' => $month,
            'rows' => $rows,
            'labels' => SourceLabels::forLines($rows->flatMap(fn (SaasCustomerMonth $row): array => $row->lineMonths())),
            'total' => $rows->sum(fn ($row): int => SaasReport::amount($row, $this->category)),
            'expected' => match ($this->category) {
                'ingaende' => $month->opening_mrr_ore,
                'utgaende' => $month->mrr_ore,
                default => (int) $month->getAttribute(SaasReport::CATEGORIES[$this->category]),
            },
            'categories' => ['ingaende', ...array_keys(SaasReport::CATEGORIES), 'utgaende'],
        ]);
    }
}
