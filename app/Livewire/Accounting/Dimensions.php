<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\SaveDimensionObject;
use App\Domain\Accounting\Dimension;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The register of cost centres or of projects.
 */
class Dimensions extends Component
{
    use ShowsToasts;

    #[Locked]
    public string $dimension;

    public string $newCode = '';

    public string $newName = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public function mount(string $dimension): void
    {
        $this->dimension = Dimension::from($dimension)->value;

        abort_unless($this->user()->can('viewAny', [$this->kind()->model(), $this->company()]), 403);
    }

    public function add(SaveDimensionObject $save): void
    {
        $this->resetErrorBag();

        $object = $save($this->user(), $this->company(), $this->kind(), ['code' => trim($this->newCode), 'name' => $this->newName]);

        $this->reset('newCode', 'newName');
        $this->toast(__(':label :name är tillagt.', ['label' => $this->kind()->label(), 'name' => $object->label()]));
    }

    public function startEditing(int $id): void
    {
        $object = $this->find($id);

        $this->editingId = $object->id;
        $this->editingName = $object->name;
        $this->resetErrorBag();
    }

    public function cancelEditing(): void
    {
        $this->reset('editingId', 'editingName');
    }

    public function saveName(SaveDimensionObject $save): void
    {
        $object = $save($this->user(), $this->company(), $this->kind(), ['name' => $this->editingName], $this->find((int) $this->editingId));

        $this->reset('editingId', 'editingName');
        $this->toast(__('Namnet är ändrat till :name.', ['name' => $object->name]));
    }

    public function toggleActive(int $id, SaveDimensionObject $save): void
    {
        $object = $this->find($id);
        $object = $save($this->user(), $this->company(), $this->kind(), ['name' => $object->name, 'is_active' => ! $object->is_active], $object);

        $this->toast($object->is_active
            ? __(':name är aktiverat igen.', ['name' => $object->label()])
            : __(':name är inaktiverat. Det går inte att välja på nya verifikationer, men finns kvar på gamla.', ['name' => $object->label()]));
    }

    public function render(): View
    {
        $model = $this->kind()->model();

        return view('livewire.accounting.dimensions', [
            'kind' => $this->kind(),
            'objects' => $model::query()->orderBy('code')->get(),
            'canManage' => $this->user()->can('create', [$model, $this->company()]),
        ]);
    }

    private function kind(): Dimension
    {
        return Dimension::from($this->dimension);
    }

    private function find(int $id): CostCenter|Project
    {
        return $this->kind()->model()::query()->findOrFail($id);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
