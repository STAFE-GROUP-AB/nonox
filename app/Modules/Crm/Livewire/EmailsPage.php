<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\HandleWaitingEmail;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\EmailStatus;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Support\CrmMailAddress;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * E-post (ADR-048): the company's +crm address and how to use it, the
 * e-mail waiting in "Ej kopplade" (crm:manage), and one logged e-mail in
 * full (?visa=, crm:view). Handling goes through HandleWaitingEmail.
 */
class EmailsPage extends Component
{
    use ActsInCompany;
    use ShowsToasts;
    use WithPagination;

    #[Url(as: 'visa', except: '')]
    public string $show = '';

    /** The waiting e-mail being linked or turned into a prospect. */
    public ?int $emailId = null;

    /** link or prospect */
    public string $panel = '';

    public string $accountSearch = '';

    public string $accountId = '';

    public string $personId = '';

    /** @var array<string, string> */
    public array $prospect = ['address' => '', 'account_name' => '', 'person_name' => '', 'kind' => 'COMPANY', 'legal_basis' => 'LEGITIMATE_INTEREST', 'consent_at' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:view'), 403);
    }

    public function startLink(int $id): void
    {
        $this->authorizeManage();
        $email = $this->waiting($id);
        $this->reset('accountSearch', 'accountId', 'personId');
        $this->emailId = $email->id;
        $this->panel = 'link';
        $this->resetErrorBag();
    }

    public function startProspect(int $id): void
    {
        $this->authorizeManage();
        $email = $this->waiting($id);
        $address = $email->otherParty()[0] ?? '';
        $domain = str_contains($address, '@') ? explode('@', $address)[1] : '';
        $this->emailId = $email->id;
        $this->prospect = [
            'address' => $address,
            'account_name' => $domain === '' ? '' : ucfirst(explode('.', $domain)[0]),
            'person_name' => $address === $email->participants['from'] ? (string) ($email->participants['from_name'] ?? '') : '',
            'kind' => 'COMPANY',
            'legal_basis' => LegalBasis::LegitimateInterest->value,
            'consent_at' => '',
        ];
        $this->panel = 'prospect';
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'emailId', 'accountSearch', 'accountId', 'personId');
        $this->resetErrorBag();
    }

    public function link(HandleWaitingEmail $handle): void
    {
        $this->authorizeManage();

        if ($this->accountId === '') {
            $this->addError('accountId', __('Välj en kund.'));

            return;
        }

        try {
            $activity = $handle->link($this->user(), $this->company(), $this->waiting((int) $this->emailId), (int) $this->accountId, $this->personId === '' ? null : (int) $this->personId);
        } catch (ValidationException $exception) {
            $this->addError('accountId', $this->firstError($exception));

            return;
        }

        $this->closePanel();
        $this->toast(__('Mejlet är loggat på :name.', ['name' => Account::query()->find($activity->account_id)?->name]));
    }

    public function createProspect(HandleWaitingEmail $handle): void
    {
        $this->authorizeManage();

        try {
            $activity = $handle->prospect($this->user(), $this->company(), $this->waiting((int) $this->emailId), $this->prospect);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["prospect.{$key}" => $messages])->all());

            return;
        }

        $this->closePanel();
        $this->toast(__('Prospektet är skapat och mejlet loggat.'));
        $this->redirectRoute('crm.accounts.show', $activity->account_id, navigate: false);
    }

    public function dismiss(int $id, HandleWaitingEmail $handle): void
    {
        $this->authorizeManage();
        $handle->dismiss($this->user(), $this->company(), $this->waiting($id));
        $this->closePanel();
        $this->toast(__('Mejlet är borttaget.'));
    }

    public function render(): View
    {
        $canManage = $this->user()->hasTeamPermission($this->company(), 'crm:manage');
        $shown = $this->show !== '' && ctype_digit($this->show) ? Email::query()->with('forwarder:id,name')->find((int) $this->show) : null;
        $search = trim($this->accountSearch);

        return view('crm::livewire.emails', [
            'canManage' => $canManage,
            'address' => $canManage ? CrmMailAddress::for($this->company()) : null,
            'viaImap' => CrmMailAddress::viaImap($this->company()),
            'shown' => $shown,
            'shownAccounts' => $shown === null ? collect() : Account::query()->whereIn('id', Activity::query()->where('email_id', $shown->id)->select('account_id'))->orderBy('name')->get(['id', 'name']),
            'waiting' => $canManage
                ? Email::query()->whereIn('status', [EmailStatus::Unmatched->value, EmailStatus::Held->value])->with('forwarder:id,name')->latest('id')->paginate(20)
                : null,
            'accounts' => $this->panel === 'link' && $search !== ''
                ? Account::query()->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%')->orderBy('name')->limit(10)->get(['id', 'name'])
                : collect(),
            'chosen' => $this->accountId === '' ? null : Account::query()->with(['people' => fn ($query) => $query->orderBy('name')])->find((int) $this->accountId),
            'current' => $this->emailId === null ? null : Email::query()->find($this->emailId),
            'kinds' => AccountKind::cases(),
            'bases' => LegalBasis::cases(),
        ]);
    }

    public function chooseAccount(int $id): void
    {
        $this->accountId = (string) Account::query()->findOrFail($id)->id;
        $this->personId = '';
    }

    private function waiting(int $id): Email
    {
        return Email::query()->whereIn('status', [EmailStatus::Unmatched->value, EmailStatus::Held->value])->findOrFail($id);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:manage'), 403);
    }
}
