<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\Domain\AgreementKind;
use App\Modules\Crm\Domain\ValuePeriod;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Money\Money;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One agreement (/kunder/avtal/{id}, and /kunder/avtal/nytt?konto=…):
 * its terms, owner, the recurring invoice that bills it, its PDF,
 * uppsägning, and signing (ADR-046). A signed agreement's terms are read
 * only.
 */
class AgreementCard extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    #[Locked]
    public ?int $agreementId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var UploadedFile|null */
    public $file = null;

    public string $terminateOn = '';

    public function mount(?int $agreementId = null): void
    {
        $user = $this->user();
        $company = $this->company();

        if ($agreementId === null) {
            abort_unless($user->can('manage', [Account::class, $company]), 403);
            $account = Account::query()->find(request()->integer('konto'));
            $this->form = [
                'account_id' => $account instanceof Account ? (string) $account->id : '',
                'title' => '',
                'kind' => AgreementKind::Service->value,
                'start_date' => CarbonImmutable::now(LogActivity::ZONE)->toDateString(),
                'end_date' => '',
                'notice_months' => '3',
                'auto_renew' => false,
                'renewal_months' => '12',
                'value' => '',
                'value_period' => ValuePeriod::Month->value,
                'recurring_invoice_id' => '',
                'owner_id' => (string) ($account instanceof Account && $account->owner_id !== null ? $account->owner_id : $user->id),
                'body_text' => '',
                'notes' => '',
            ];

            return;
        }

        $agreement = Agreement::query()->findOrFail($agreementId);
        abort_unless($user->can('view', $agreement), 403);
        $this->agreementId = $agreement->id;
        $this->fill_($agreement);
    }

    public function save(SaveAgreement $save): void
    {
        $this->resetErrorBag();
        $input = [
            ...$this->form,
            'account_id' => $this->form['account_id'] === '' ? null : (int) $this->form['account_id'],
            'end_date' => ($this->form['end_date'] ?? '') === '' ? null : $this->form['end_date'],
            'notice_months' => (int) ($this->form['notice_months'] ?? 0),
            'auto_renew' => (bool) ($this->form['auto_renew'] ?? false),
            'renewal_months' => ($this->form['renewal_months'] ?? '') === '' ? null : (int) $this->form['renewal_months'],
            'recurring_invoice_id' => ($this->form['recurring_invoice_id'] ?? '') === '' ? null : $this->form['recurring_invoice_id'],
            'owner_id' => ($this->form['owner_id'] ?? '') === '' ? null : (int) $this->form['owner_id'],
        ];

        $agreement = $save($this->user(), $this->company(), $input, $this->agreementId === null ? null : $this->agreement());

        if ($this->agreementId === null) {
            Toast::flash(__('Avtalet är sparat.'));
            $this->redirectRoute('crm.agreements.show', ['agreement' => $agreement->id]);

            return;
        }

        $this->fill_($agreement->refresh());
        $this->toast(__('Avtalet är sparat.'));
    }

    public function upload(SaveAgreement $save): void
    {
        $this->resetErrorBag();
        $this->validate(['file' => ['required', 'file']], [], ['file' => __('fil')]);

        /** @var UploadedFile $file */
        $file = $this->file;
        $save->attach($this->user(), $this->company(), $this->agreement(), $file);

        $this->file = null;
        $this->toast(__('PDF:en är sparad med avtalet.'));
    }

    public function terminate(SaveAgreement $save): void
    {
        $this->resetErrorBag();
        $on = $this->terminateOn === '' ? CarbonImmutable::now(LogActivity::ZONE)->toDateString() : $this->terminateOn;
        $save->terminate($this->user(), $this->company(), $this->agreement(), $on);

        $this->toast(__('Avtalet är uppsagt och förlängs inte.'));
    }

    public function withdrawTermination(SaveAgreement $save): void
    {
        $save->terminate($this->user(), $this->company(), $this->agreement(), null);

        $this->toast(__('Uppsägningen är återtagen.'));
    }

    public function render(): View
    {
        $agreement = $this->agreementId === null ? null : $this->agreement()->load(['account:id,name,customer_id', 'recurringInvoice:id,number,name']);
        $user = $this->user();
        $company = $this->company();
        $accountId = $this->form['account_id'] === '' ? null : (int) $this->form['account_id'];
        $customerId = $accountId === null ? null : Account::query()->whereKey($accountId)->value('customer_id');
        $canSeeInvoices = $user->hasTeamPermission($company, 'invoices:view');

        return view('crm::livewire.agreement-card', [
            'agreement' => $agreement,
            'canManage' => $agreement === null ? $user->can('manage', [Account::class, $company]) : $user->can('update', $agreement),
            'locked' => $agreement?->signed_at !== null,
            'accounts' => Account::query()->orderBy('name')->limit(1000)->get(['id', 'name']),
            'members' => CompanyMembers::all($company),
            'kinds' => AgreementKind::cases(),
            'periods' => ValuePeriod::cases(),
            'canSeeInvoices' => $canSeeInvoices,
            'recurring' => $canSeeInvoices && $customerId !== null ? RecurringInvoice::query()->where('customer_id', $customerId)->orderBy('number')->get(['id', 'number', 'name']) : collect(),
            'today' => CarbonImmutable::now(LogActivity::ZONE)->startOfDay(),
        ]);
    }

    private function fill_(Agreement $agreement): void
    {
        $this->form = [
            'account_id' => (string) $agreement->account_id,
            'title' => $agreement->title,
            'kind' => $agreement->kind->value,
            'start_date' => $agreement->start_date->toDateString(),
            'end_date' => (string) $agreement->end_date?->toDateString(),
            'notice_months' => (string) $agreement->notice_months,
            'auto_renew' => $agreement->auto_renew,
            'renewal_months' => (string) ($agreement->renewal_months ?? ''),
            'value' => $agreement->value_ore === 0 ? '' : Money::ofOre($agreement->value_ore)->format(withCurrency: false),
            'value_period' => $agreement->value_period->value,
            'recurring_invoice_id' => (string) $agreement->recurring_invoice_id,
            'owner_id' => (string) $agreement->owner_id,
            'body_text' => (string) $agreement->body_text,
            'notes' => (string) $agreement->notes,
        ];
        $this->terminateOn = '';
    }

    private function agreement(): Agreement
    {
        return Agreement::query()->findOrFail((int) $this->agreementId);
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
