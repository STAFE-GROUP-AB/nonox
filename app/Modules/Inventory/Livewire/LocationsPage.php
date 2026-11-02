<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\StockLocation;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lager → Lagerställen: the location tree in walking order, with search,
 * a form to add a location under another, and changing or deactivating
 * one, and printing location labels for one location or a whole branch.
 * No dragging: a location's place is chosen when it is created.
 */
class LocationsPage extends Component
{
    use InventoryPage;

    #[Url(as: 'sok')]
    public string $search = '';

    #[Url(as: 'visa')]
    public string $show = 'aktiva';

    /** null: no form open; 0: a new location; otherwise the location being edited */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var ?array<int, int> location id => 1 while the label print step is open */
    public ?array $printing = null;

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
    }

    public function create(?int $parentId = null): void
    {
        abort_unless($this->can('manage'), 403);
        $parent = $parentId === null ? null : StockLocation::query()->find($parentId);
        $next = $parent === null ? LocationType::Warehouse : (LocationType::cases()[min(3, $parent->type->depth() + 1)]);

        $this->editing = 0;
        $this->form = ['type' => $next->value, 'parent_id' => $parent?->id, 'code' => '', 'name' => '', 'address_line1' => '', 'postal_code' => '', 'city' => '', 'is_active' => true];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        abort_unless($this->can('manage'), 403);
        $location = StockLocation::query()->findOrFail($id);

        $this->editing = $location->id;
        $this->form = [
            'type' => $location->type->value,
            'parent_id' => $location->parent_id,
            'code' => $location->code,
            'name' => (string) $location->name,
            'address_line1' => (string) $location->address_line1,
            'postal_code' => (string) $location->postal_code,
            'city' => (string) $location->city,
            'is_active' => $location->is_active,
        ];
        $this->resetErrorBag();
    }

    /**
     * Opens the label print step for one location, or with $branch for it
     * and every active location below it, in walking order (ADR-041).
     */
    public function printLabels(int $id, bool $branch = false): void
    {
        abort_unless($this->can('printLabels'), 403);
        $location = StockLocation::query()->findOrFail($id);

        $ids = $branch
            ? StockLocation::query()->where('is_active', true)->where(fn ($query) => $query->whereKey($location->id)->orWhere('full_code', 'like', $location->full_code.'-%'))->orderBy('sort_key')->pluck('id')->all()
            : [$location->id];

        $this->printing = array_fill_keys($ids, 1);
    }

    #[On('labels-printed')]
    #[On('labels-closed')]
    public function closeLabels(): void
    {
        $this->reset('printing');
    }

    public function cancel(): void
    {
        $this->reset('editing', 'form');
        $this->resetErrorBag();
    }

    public function save(SaveLocation $save): void
    {
        $location = $this->editing ? StockLocation::query()->findOrFail($this->editing) : null;
        $ok = $this->attempt(function () use ($save, $location): void {
            $save($this->user(), $this->company(), [...$this->form, 'parent_id' => ($this->form['parent_id'] ?? null) ?: null], $location);
        }, $location === null ? __('Lagerstället är tillagt.') : __('Lagerstället är sparat.'));

        if ($ok) {
            $this->cancel();
        }
    }

    public function toggle(int $id, SaveLocation $save): void
    {
        $location = StockLocation::query()->findOrFail($id);

        $this->attempt(fn () => $save($this->user(), $this->company(), [
            'code' => $location->code,
            'name' => $location->name,
            'address_line1' => $location->address_line1,
            'postal_code' => $location->postal_code,
            'city' => $location->city,
            'is_active' => ! $location->is_active,
        ], $location), $location->is_active ? __('Lagerstället :code är inaktivt.', ['code' => $location->full_code]) : __('Lagerstället :code är aktivt igen.', ['code' => $location->full_code]), '');
    }

    public function render(): View
    {
        $term = trim($this->search);

        $locations = StockLocation::query()
            ->withSum('levels as stock_milli', 'quantity_milli')
            ->when($this->show !== 'alla', fn ($query) => $query->where('is_active', true))
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('full_code', 'ilike', '%'.$term.'%')->orWhere('name', 'ilike', '%'.$term.'%')))
            ->orderBy('sort_key')
            ->get();

        return view('inventory::livewire.locations', [
            'locations' => $locations,
            'parents' => StockLocation::query()->where('is_active', true)->where('type', '<>', LocationType::Bin->value)->orderBy('sort_key')->get(['id', 'full_code', 'name', 'type']),
            'types' => LocationType::cases(),
            'canManage' => $this->can('manage'),
            'canPrint' => $this->can('printLabels'),
        ]);
    }
}
