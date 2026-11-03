<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Livewire\Concerns\EditsPeople;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Suppression;
use App\Modules\Crm\Support\PruneCandidates;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Personer (ADR-043): every contact person, with the accounts they are at.
 * Add, change and erase people here or on an account's card. "Gallras
 * snart" lists the people and prospects the retention rule erases within 30
 * days, so someone can keep a contact by logging an activity.
 */
class PeoplePage extends Component
{
    use EditsPeople;
    use ShowsToasts;
    use WithPagination;

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    /** '' for everyone, 'gallras' for those the retention rule erases soon. */
    #[Url(as: 'visa', except: '')]
    public string $show = '';

    public string $panel = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Person::class, $this->company()]), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'show'], true)) {
            $this->resetPage();
        }
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'personForm', 'editingPersonId', 'mentionCount');
        $this->resetErrorBag();
    }

    protected function personSaved(Person $person, bool $created): void
    {
        $this->closePanel();
        $this->toast($created ? __(':name är tillagd.', ['name' => $person->name]) : __(':name är sparad.', ['name' => $person->name]));
    }

    protected function personAccountId(): ?int
    {
        return null;
    }

    public function render(): View
    {
        $search = trim($this->search);
        $pruning = $this->show === 'gallras';
        $cutoff = CarbonImmutable::now()->addDays(CrmSettings::PRUNE_NOTICE_DAYS)->subMonthsNoOverflow(CrmSettings::for($this->company())['prune_after_months']);

        $people = ($pruning ? PruneCandidates::people($cutoff) : Person::query())
            ->with(['accounts' => fn ($query) => $query->orderBy('name')])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->orWhere('title', 'ilike', "%{$search}%")))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);

        return view('crm::livewire.people', [
            'people' => $people,
            'prospects' => $pruning ? PruneCandidates::prospects($cutoff)->orderBy('name')->limit(200)->get(['id', 'name', 'last_activity_at', 'updated_at']) : collect(),
            'canManage' => $this->user()->can('manage', [Person::class, $this->company()]),
            'pruning' => $pruning,
            'suppressions' => $this->show === 'sparrlista' ? Suppression::query()->orderBy('updated_at')->limit(500)->get() : collect(),
            'reviewBefore' => CarbonImmutable::now()->subYears(CrmSettings::for($this->company())['suppression_review_years']),
            'months' => CrmSettings::for($this->company())['prune_after_months'],
        ]);
    }

    /** The objection still stands: the entry counts as reviewed today. */
    public function keepSuppression(int $id): void
    {
        $this->authorizeManage();
        $entry = Suppression::query()->findOrFail($id);
        $entry->touch();

        Audit::record('crm.suppression_reviewed', $entry, ['kept' => true], $this->company(), $this->user());
        $this->toast(__('Raden är granskad och finns kvar.'));
    }

    /** The objection no longer applies, or the entry is no longer needed. */
    public function removeSuppression(int $id): void
    {
        $this->authorizeManage();
        $entry = Suppression::query()->findOrFail($id);
        $entry->delete();

        Audit::record('crm.suppression_reviewed', null, ['kept' => false, 'suppression_id' => $id], $this->company(), $this->user());
        $this->toast(__('Raden är borttagen från spärrlistan.'));
    }

    private function authorizeManage(): void
    {
        abort_unless($this->user()->can('manage', [Person::class, $this->company()]), 403);
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
