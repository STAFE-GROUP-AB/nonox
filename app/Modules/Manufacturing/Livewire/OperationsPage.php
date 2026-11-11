<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire;

use App\Modules\Manufacturing\Actions\SaveOperation;
use App\Modules\Manufacturing\Livewire\Concerns\ManufacturingPage;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionSkill;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tillverkning → Arbetssteg (ADR-071 §3, §6): the operations with their
 * hourly cost and capacity, how many bills of materials use each, adding
 * and changing them (manufacturing:manage), and archiving. One query for
 * the list and one for the counts, whatever the number of operations.
 */
class OperationsPage extends Component
{
    use ManufacturingPage;

    #[Url(as: 'arkiverade')]
    public bool $showArchived = false;

    #[Locked]
    public ?int $editingId = null;

    public bool $editing = false;

    /** @var array<string, string> */
    public array $form = ['name' => '', 'hourly_cost' => '', 'capacity_hours' => '', 'skill_id' => '', 'wait_hours' => ''];

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
    }

    public function create(): void
    {
        abort_unless($this->can('manage'), 403);
        $this->editingId = null;
        $this->form = ['name' => '', 'hourly_cost' => '', 'capacity_hours' => '', 'skill_id' => '', 'wait_hours' => ''];
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        abort_unless($this->can('manage'), 403);
        $operation = Operation::query()->findOrFail($id);
        $this->editingId = $operation->id;
        $this->form = [
            'name' => $operation->name,
            'hourly_cost' => Money::ofOre($operation->hourly_cost_ore)->format(withCurrency: false),
            'capacity_hours' => $operation->daily_capacity_minutes === null ? '' : str_replace('.', ',', rtrim(rtrim((string) BigDecimal::of($operation->daily_capacity_minutes)->dividedBy(60, 2, RoundingMode::HalfUp), '0'), '.')),
            'skill_id' => $operation->required_skill_id === null ? '' : (string) $operation->required_skill_id,
            'wait_hours' => $operation->wait_minutes === 0 ? '' : str_replace('.', ',', rtrim(rtrim((string) BigDecimal::of($operation->wait_minutes)->dividedBy(60, 2, RoundingMode::HalfUp), '0'), '.')),
        ];
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->editingId = null;
        $this->form = ['name' => '', 'hourly_cost' => '', 'capacity_hours' => '', 'skill_id' => '', 'wait_hours' => ''];
        $this->resetErrorBag();
    }

    public function save(SaveOperation $save): void
    {
        abort_unless($this->can('manage'), 403);
        $operation = $this->editingId === null ? null : Operation::query()->findOrFail($this->editingId);
        $saved = null;

        $ok = $this->attempt(function () use ($save, $operation, &$saved): void {
            $saved = $save($this->user(), $this->company(), $this->form, $operation);
        }, '');

        if (! $ok || ! $saved instanceof Operation) {
            return;
        }

        $this->toast($operation === null ? __('Arbetssteget :name är tillagt.', ['name' => $saved->name]) : __('Arbetssteget :name är sparat.', ['name' => $saved->name]));
        $this->cancel();
    }

    public function archive(int $id, bool $archived, SaveOperation $save): void
    {
        abort_unless($this->can('manage'), 403);
        $operation = Operation::query()->findOrFail($id);

        $this->attempt(fn () => $save->archive($this->user(), $this->company(), $operation, $archived), $archived
            ? __('Arbetssteget :name är arkiverat.', ['name' => $operation->name])
            : __('Arbetssteget :name används igen.', ['name' => $operation->name]));
    }

    public function render(): View
    {
        $operations = Operation::query()
            ->when(! $this->showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->withCount(['bomRows as used_in' => fn ($query) => $query->select(DB::raw('count(distinct bill_of_materials_id)'))])
            ->with('requiredSkill:id,name')
            ->orderBy('position')->orderBy('name')
            ->get();

        return view('manufacturing::livewire.operations', [
            'operations' => $operations,
            'canManage' => $this->can('manage'),
            'skills' => ProductionSkill::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
