<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Units\SaveUnit;
use App\Actions\Units\SetRowDefaultUnit;
use App\Actions\Units\SetUnitArchived;
use App\Domain\Units\UnitCatalogue;
use App\Domain\Units\UnitDefaults;
use App\Domain\Units\UnitDimension;
use App\Domain\Units\UnitRegister;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Article;
use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The company's unit catalogue (ADR-071 §12.1): the units articles can be
 * counted, bought and sold in, with the ones every company starts with and
 * those the company adds (rulle, bunt, pall), and the one new rows start
 * with. It is the only source of units on invoices, quotes, orders,
 * templates and work orders (§12.9). Reached from Artiklar. A fixed
 * number of queries for the page, whatever the number of units.
 */
class Units extends Component
{
    use ShowsToasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array{code: string, name: string, dimension: string, rec20_code: string} */
    public array $form = ['code' => '', 'name' => '', 'dimension' => 'OTHER', 'rec20_code' => ''];

    public bool $showArchived = false;

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Article::class, $this->company()]), 403);
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'dimension' => UnitDimension::Other->value, 'rec20_code' => ''];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function edit(int $unitId): void
    {
        abort_unless($this->canManage(), 403);
        $unit = Unit::query()->findOrFail($unitId);
        $this->editingId = $unit->id;
        $this->form = ['code' => $unit->code, 'name' => $unit->name, 'dimension' => $unit->dimension->value, 'rec20_code' => (string) $unit->rec20_code];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function save(SaveUnit $save): void
    {
        abort_unless($this->canManage(), 403);
        $unit = $save($this->user(), $this->company(), $this->form, $this->editingId === null ? null : Unit::query()->findOrFail($this->editingId));

        $this->toast($this->editingId === null
            ? __('Enheten :code är tillagd.', ['code' => $unit->code])
            : __('Enheten :code är sparad.', ['code' => $unit->code]));
        $this->cancel();
    }

    public function archive(int $unitId, SetUnitArchived $archive): void
    {
        abort_unless($this->canManage(), 403);
        $unit = $archive($this->user(), $this->company(), $unitId, true);
        $this->toast(__('Enheten :code är arkiverad och föreslås inte längre.', ['code' => $unit->code]));
    }

    public function restore(int $unitId, SetUnitArchived $archive): void
    {
        abort_unless($this->canManage(), 403);
        $unit = $archive($this->user(), $this->company(), $unitId, false);
        $this->toast(__('Enheten :code används igen.', ['code' => $unit->code]));
    }

    public function makeRowDefault(int $unitId, SetRowDefaultUnit $setDefault): void
    {
        abort_unless($this->canManage(), 403);
        $unit = $setDefault($this->user(), $this->company(), $unitId);
        $this->toast(__('Nya rader får enheten :code.', ['code' => $unit->code]));
    }

    public function render(): View
    {
        if ($this->canManage() && ! Unit::query()->exists()) {
            UnitCatalogue::seed((int) $this->company()->id);
        }

        $units = Unit::query()->orderBy('is_default', 'desc')->orderBy('code')->get();
        $register = UnitRegister::load();

        return view('livewire.invoicing.units', [
            'units' => $this->showArchived ? $units : $units->reject(fn (Unit $unit): bool => $unit->isArchived())->values(),
            'archivedCount' => $units->filter(fn (Unit $unit): bool => $unit->isArchived())->count(),
            'canManage' => $this->canManage(),
            'rowDefaultId' => $register->default()->id,
            'dimensions' => UnitDimension::cases(),
            'rec20' => UnitDefaults::REC20_CHOICES,
        ]);
    }

    private function canManage(): bool
    {
        return $this->user()->can('create', [Article::class, $this->company()]);
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
