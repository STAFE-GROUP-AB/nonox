<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\CreateCallList;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\CallList;
use App\Modules\Crm\Models\CallListItem;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ringlistor (ADR-047): the company's call lists with their progress, and
 * a form that builds a list from the CRM's own accounts (status, town,
 * tag). Lists from the register are built on Prospektera. Every CRM user
 * sees every list; "Mina" is a filter, as for deals.
 */
class CallListsPage extends Component
{
    #[Url(as: 'visa', except: 'alla')]
    public string $show = 'alla';

    #[Url(as: 'arkiverade', except: false)]
    public bool $archived = false;

    public bool $showForm = false;

    /** @var array<string, string> */
    public array $form = ['name' => '', 'assigned_to' => '', 'status' => 'PROSPECT', 'city' => '', 'tag' => ''];

    /** Take private persons and sole traders along too (they need a NIX check before a sales call). */
    public bool $includePrivate = false;

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Account::class, $this->company()]), 403);
    }

    public function start(): void
    {
        abort_unless($this->canManage(), 403);

        $this->form = ['name' => '', 'assigned_to' => (string) $this->user()->id, 'status' => AccountStatus::Prospect->value, 'city' => '', 'tag' => ''];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->showForm = false;
    }

    public function create(CreateCallList $create): void
    {
        $ids = array_values($this->matching()->orderBy('name')->orderBy('id')->limit(CallList::MAX_ITEMS)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        $made = $create($this->user(), $this->company(), [
            'name' => $this->form['name'],
            'assigned_to' => $this->form['assigned_to'] === '' ? null : (int) $this->form['assigned_to'],
            'include_private' => $this->includePrivate,
        ], $ids, ['source' => 'accounts', 'filters' => array_filter(['status' => $this->form['status'], 'city' => $this->form['city'], 'tag' => $this->form['tag']])]);

        Toast::flash($made['left_out'] > 0
            ? __('Ringlistan är skapad. :count togs inte med: de är förlorade, inaktiva, privatpersoner eller enskilda firmor, eller har spärrat telefonförsäljning.', ['count' => $made['left_out']])
            : __('Ringlistan är skapad.'));
        $this->redirectRoute('crm.call-lists.show', ['callList' => $made['list']->id]);
    }

    public function render(): View
    {
        $lists = CallList::query()
            ->with('assignee:id,name')
            ->withCount([
                'items',
                'items as done_count' => fn (Builder $query) => $query->where('status', '<>', CallListItem::PENDING),
            ])
            ->when($this->show === 'mina', fn (Builder $query) => $query->where('assigned_to', $this->user()->id))
            ->when($this->archived, fn (Builder $query) => $query->whereNotNull('archived_at'), fn (Builder $query) => $query->whereNull('archived_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return view('crm::livewire.call-lists', [
            'lists' => $lists,
            'canManage' => $this->canManage(),
            'members' => $this->showForm ? CompanyMembers::all($this->company()) : collect(),
            'statuses' => AccountStatus::cases(),
            'cities' => $this->showForm ? Account::query()->whereNotNull('city')->distinct()->orderBy('city')->limit(200)->pluck('city') : collect(),
            'tags' => $this->showForm ? Account::query()->toBase()->selectRaw('DISTINCT jsonb_array_elements_text(tags) AS tag')->orderBy('tag')->limit(200)->pluck('tag') : collect(),
            'matching' => $this->showForm ? $this->matching()->count() : 0,
        ]);
    }

    /** @return Builder<Account> */
    private function matching(): Builder
    {
        return Account::query()
            ->when(AccountStatus::tryFrom($this->form['status']) !== null, fn (Builder $query) => $query->where('status', $this->form['status']))
            ->when($this->form['city'] !== '', fn (Builder $query) => $query->where('city', $this->form['city']))
            ->when($this->form['tag'] !== '', fn (Builder $query) => $query->whereJsonContains('tags', mb_strtolower($this->form['tag'])));
    }

    private function canManage(): bool
    {
        return $this->user()->can('manage', [Account::class, $this->company()]);
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
