<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Actions\CompleteActivity;
use App\Modules\Crm\Actions\ConvertToCustomer;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\Domain\AccountFigures;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Domain\Timeline;
use App\Modules\Crm\Livewire\Concerns\EditsPeople;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * An account's card (ADR-043): status and owner, key figures from the
 * ledger, the people and their roles, the next step, quick buttons for a
 * call, a meeting, a note and a task, and the timeline of activities and
 * ledger events. Completing an activity asks for the next step; skipping it
 * is one tap. Built for the phone first.
 */
class AccountCard extends Component
{
    use EditsPeople;
    use ShowsToasts;
    use WithPagination;

    #[Locked]
    public int $accountId;

    /** The open panel: activity, complete, next, person, link, edit or status; '' for none. */
    public string $panel = '';

    /** @var array<string, mixed> */
    public array $activity = [];

    #[Locked]
    public ?int $editingActivityId = null;

    /** @var array<string, string> */
    public array $completion = ['call_outcome' => '', 'note' => ''];

    /** @var array<string, mixed> */
    public array $edit = [];

    public string $newStatus = '';

    public string $lostReason = '';

    public string $linkPersonId = '';

    public string $linkRole = 'OTHER';

    public function mount(int $accountId): void
    {
        $account = Account::query()->findOrFail($accountId);
        abort_unless($this->user()->can('view', $account), 403);

        $this->accountId = $account->id;
    }

    /** Ring, Möte, Anteckning or Uppgift: the activity form, ready for that type. */
    public function startActivity(string $type, ?int $personId = null): void
    {
        $this->authorizeManage();
        $type = ActivityType::tryFrom($type) ?? ActivityType::Note;

        $this->editingActivityId = null;
        $this->activity = [
            'type' => $type->value,
            'subject' => '',
            'body' => '',
            'person_id' => $personId === null ? '' : (string) $personId,
            'call_outcome' => $type === ActivityType::Call ? CallOutcome::Reached->value : '',
            'due_at' => $type === ActivityType::Meeting || $type === ActivityType::Task ? CarbonImmutable::now(LogActivity::ZONE)->addDay()->setTime(9, 0)->format('Y-m-d\TH:i') : '',
            'assigned_to' => (string) $this->user()->id,
            // A call or an e-mail is usually logged afterwards; a meeting or a task is planned.
            'done' => $type === ActivityType::Call || $type === ActivityType::Email,
        ];
        $this->panel = 'activity';
        $this->resetErrorBag();
    }

    public function editActivity(int $activityId): void
    {
        $this->authorizeManage();
        $activity = $this->activities()->findOrFail($activityId);

        $this->editingActivityId = $activity->id;
        $this->activity = [
            'type' => $activity->type->value,
            'subject' => $activity->subject,
            'body' => (string) $activity->body,
            'person_id' => (string) $activity->person_id,
            'call_outcome' => (string) $activity->call_outcome?->value,
            'due_at' => (string) $activity->due_at?->setTimezone(LogActivity::ZONE)->format('Y-m-d\TH:i'),
            'assigned_to' => (string) $activity->assigned_to,
            'done' => ! $activity->isOpen(),
        ];
        $this->panel = 'activity';
        $this->resetErrorBag();
    }

    public function saveActivity(LogActivity $log): void
    {
        $existing = $this->editingActivityId === null ? null : $this->activities()->findOrFail($this->editingActivityId);
        $saved = $log($this->user(), $this->company(), [
            ...$this->activity,
            'account_id' => $this->accountId,
            'person_id' => ($this->activity['person_id'] ?? '') === '' ? null : (int) $this->activity['person_id'],
            'assigned_to' => ($this->activity['assigned_to'] ?? '') === '' ? null : (int) $this->activity['assigned_to'],
            'call_outcome' => ($this->activity['call_outcome'] ?? '') === '' ? null : $this->activity['call_outcome'],
        ], $existing);

        $this->closePanel();
        $this->toast(match (true) {
            $existing !== null => __('Aktiviteten är sparad.'),
            $saved->isOpen() => __(':type är planerad.', ['type' => $saved->type->label()]),
            default => __(':type är loggad.', ['type' => $saved->type->label()]),
        });
    }

    /** Klar: asks for the outcome and a short note, then the next step. */
    public function startCompletion(int $activityId): void
    {
        $this->authorizeManage();
        $activity = $this->activities()->whereNull('done_at')->findOrFail($activityId);

        $this->editingActivityId = $activity->id;
        $this->completion = ['call_outcome' => $activity->type === ActivityType::Call ? CallOutcome::Reached->value : '', 'note' => ''];
        $this->panel = 'complete';
        $this->resetErrorBag();
    }

    public function complete(CompleteActivity $complete): void
    {
        $activity = $this->activities()->findOrFail((int) $this->editingActivityId);

        try {
            $complete($this->user(), $this->company(), $activity, [
                'call_outcome' => $this->completion['call_outcome'] === '' ? null : $this->completion['call_outcome'],
                'note' => $this->completion['note'],
            ]);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
            $this->closePanel();

            return;
        }

        $this->toast(__('Klart. Vad är nästa steg?'));
        $this->startActivity(ActivityType::Task->value, $activity->person_id);
        $this->panel = 'next';
    }

    public function skipNextStep(): void
    {
        $this->closePanel();
        $this->toast(__('Inget nästa steg planerat.'), 'info');
    }

    public function startEdit(): void
    {
        $this->authorizeManage();
        $account = $this->account();

        $this->edit = [
            ...array_map(fn (mixed $value): string => (string) $value, $account->only(['name', 'org_number', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country', 'sni_code', 'website', 'source', 'owner_id', 'parent_id'])),
            'kind' => $account->kind->value,
            'tags' => implode(', ', $account->tags),
        ];
        $this->panel = 'edit';
        $this->resetErrorBag();
    }

    public function saveEdit(SaveAccount $save): void
    {
        $account = $this->account();
        $input = [...$this->edit, 'owner_id' => ($this->edit['owner_id'] ?? '') === '' ? null : (int) $this->edit['owner_id'], 'parent_id' => ($this->edit['parent_id'] ?? '') === '' ? null : (int) $this->edit['parent_id']];

        if (! $this->canEditShared($account)) {
            $input = array_diff_key($input, array_flip(array_diff(Account::CUSTOMER_FIELDS, ['name'])));
            $input['name'] = $account->name;
        }

        $save($this->user(), $this->company(), $input, $account);

        $this->closePanel();
        $this->toast(__('Uppgifterna är sparade.'));
    }

    public function startStatus(): void
    {
        $this->authorizeManage();
        $account = $this->account();

        $this->newStatus = $account->status->value;
        $this->lostReason = (string) $account->lost_reason;
        $this->panel = 'status';
        $this->resetErrorBag();
    }

    public function saveStatus(SetAccountStatus $set): void
    {
        $account = $set($this->user(), $this->company(), $this->account(), $this->newStatus, $this->lostReason);

        $this->closePanel();
        $this->toast(__('Statusen är nu :status.', ['status' => mb_strtolower($account->status->label())]));
    }

    public function startLink(): void
    {
        $this->authorizeManage();
        $this->linkPersonId = '';
        $this->linkRole = PersonRole::Other->value;
        $this->panel = 'link';
        $this->resetErrorBag();
    }

    public function link(LinkPerson $link): void
    {
        $this->validate(['linkPersonId' => ['required', 'integer'], 'linkRole' => ['required', 'in:'.implode(',', array_column(PersonRole::cases(), 'value'))]], [], ['linkPersonId' => __('person')]);
        $person = Person::query()->findOrFail((int) $this->linkPersonId);

        $link($this->user(), $this->company(), $this->account(), $person, PersonRole::from($this->linkRole));

        $this->closePanel();
        $this->toast(__(':name är kopplad till kunden.', ['name' => $person->name]));
    }

    public function changeRole(int $personId, string $role, LinkPerson $link): void
    {
        $person = $this->account()->people()->findOrFail($personId);

        $link($this->user(), $this->company(), $this->account(), $person, PersonRole::tryFrom($role) ?? PersonRole::Other);

        $this->toast(__('Rollen är ändrad.'));
    }

    public function unlink(int $personId, LinkPerson $link): void
    {
        $person = $this->account()->people()->findOrFail($personId);

        $link->unlink($this->user(), $this->company(), $this->account(), $person);

        $this->toast(__(':name är inte längre kopplad till kunden. Personen finns kvar under Personer.', ['name' => $person->name]));
    }

    public function convert(ConvertToCustomer $convert): void
    {
        try {
            $customer = $convert($this->user(), $this->company(), $this->account());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__(':name är nu kund nummer :number.', ['name' => $customer->name, 'number' => $customer->number]));
    }

    /** GDPR erasure of a prospect, on request; a customer is erased in the customer register. */
    public function eraseProspect(EraseCrmData $erase): void
    {
        try {
            $erase->prospect($this->user(), $this->company(), $this->account());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        Toast::flash(__('Prospektet och dess aktiviteter är raderade.'));
        $this->redirectRoute('invoicing.customers');
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'activity', 'editingActivityId', 'completion', 'edit', 'newStatus', 'lostReason', 'linkPersonId', 'linkRole', 'personForm', 'editingPersonId');
        $this->resetErrorBag();
    }

    protected function personSaved(Person $person, bool $created): void
    {
        $this->closePanel();
        $this->toast($created ? __(':name är tillagd.', ['name' => $person->name]) : __(':name är sparad.', ['name' => $person->name]));
    }

    protected function personAccountId(): ?int
    {
        return $this->accountId;
    }

    public function render(AccountFigures $figures, Timeline $timeline): View
    {
        $account = $this->account()->load(['owner:id,name', 'parent:id,name', 'customer', 'people' => fn ($query) => $query->orderBy('name')]);
        $company = $this->company();
        $user = $this->user();
        $now = CarbonImmutable::now();
        $canSeeInvoices = $user->hasTeamPermission($company, 'invoices:view');
        // A private person's debts and payment habits are for those who may see the invoices, not for a seller.
        $limitedLedger = ! $canSeeInvoices && $account->kind === AccountKind::Person;

        return view('crm::livewire.account-card', [
            'account' => $account,
            'figures' => $figures->forCustomer($account->customer_id),
            'timeline' => $timeline->for($account, withReminders: ! $limitedLedger),
            'canSeeInvoices' => $canSeeInvoices,
            'limitedLedger' => $limitedLedger,
            'next' => $this->activities()->whereNull('done_at')->with(['person:id,name', 'assignee:id,name'])->orderByRaw('due_at ASC NULLS LAST')->orderBy('id')->limit(5)->get(),
            'canManage' => $user->can('update', $account),
            'canEditCustomer' => $account->customer instanceof Customer && $user->can('update', $account->customer),
            'canConvert' => $account->customer_id === null && $user->can('update', $account) && $user->can('create', [Customer::class, $company]),
            // The editors offer only active customers whose processing is not restricted; an anonymised customer is inactive.
            'canSell' => $account->customer instanceof Customer && $account->customer->is_active && ! $account->customer->isRestricted() && $user->hasTeamPermission($company, 'invoices:create'),
            'canExport' => $account->customer instanceof Customer && $user->can('update', $account->customer),
            'members' => CompanyMembers::all($company),
            'types' => ActivityType::cases(),
            'outcomes' => CallOutcome::cases(),
            'roles' => PersonRole::cases(),
            'statuses' => AccountStatus::cases(),
            'kinds' => AccountKind::cases(),
            'otherPeople' => $this->panel === 'link' ? Person::query()->whereNotIn('id', $account->people->pluck('id'))->orderBy('name')->limit(500)->get(['id', 'name', 'title']) : collect(),
            'parents' => $this->panel === 'edit' ? Account::query()->whereKeyNot($account->id)->orderBy('name')->limit(500)->get(['id', 'name']) : collect(),
            'sharedLocked' => ! $this->canEditShared($account),
            'mentions' => $this->panel === 'person' && $this->editingPersonId !== null ? app(EraseCrmData::class)->mentions(Person::query()->findOrFail($this->editingPersonId)) : 0,
            'now' => $now,
        ]);
    }

    private function canEditShared(Account $account): bool
    {
        if ($account->customer_id === null) {
            return true;
        }

        $customer = Customer::query()->find($account->customer_id);

        return $customer instanceof Customer && $this->user()->can('update', $customer);
    }

    /** @return Builder<Activity> */
    private function activities(): Builder
    {
        return Activity::query()->where('account_id', $this->accountId);
    }

    private function account(): Account
    {
        return Account::query()->findOrFail($this->accountId);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->user()->can('update', $this->account()), 403);
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
