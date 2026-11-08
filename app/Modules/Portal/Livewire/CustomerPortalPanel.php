<?php

declare(strict_types=1);

namespace App\Modules\Portal\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\ModuleManager;
use App\Modules\Portal\Actions\ManagePortalContacts;
use App\Modules\Portal\Models\PortalContact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Kundportal" on the customer card (ADR-075 §3): who has access, when they
 * last signed in, Bjud in (the customer's own address and, with CRM on, the
 * account's people with the invoice recipients first) and Stäng av / Ta
 * bort with a confirmation. Shown while the module is on, to those with
 * customer-portal:view; changes need customer-portal:manage.
 */
class CustomerPortalPanel extends Component
{
    use ShowsToasts;

    public const string NAME = 'portal.customer-panel';

    #[Locked]
    public int $customerId;

    public string $name = '';

    public string $email = '';

    public string $personId = '';

    public bool $showForm = false;

    public function mount(int $customerId): void
    {
        abort_unless(app(ModuleManager::class)->isEnabled('portal', $this->company()), 404);
        abort_unless($this->user()->hasTeamPermission($this->company(), 'customer-portal:view'), 403);
        $this->customerId = Customer::query()->findOrFail($customerId)->id;
    }

    public function startInvite(): void
    {
        $customer = Customer::query()->findOrFail($this->customerId);
        $this->showForm = true;
        $this->personId = '';
        $this->name = $customer->personal_number !== null ? $customer->name : '';
        $this->email = (string) $customer->email;
    }

    public function updatedPersonId(string $value): void
    {
        $person = $this->people()->firstWhere('id', (int) $value);

        if ($person !== null) {
            $this->name = $person['name'];
            $this->email = (string) $person['email'];
        }
    }

    public function invite(ManagePortalContacts $manage): void
    {
        $this->resetErrorBag();
        $manage->invite($this->user(), $this->company(), Customer::query()->findOrFail($this->customerId), [
            'name' => $this->name,
            'email' => $this->email,
            'crm_person_id' => $this->personId === '' ? null : (int) $this->personId,
        ]);

        $this->toast(__('Inbjudan till kundportalen är skickad till :email.', ['email' => $this->email]));
        $this->reset('showForm', 'name', 'email', 'personId');
    }

    public function disable(int $contactId, ManagePortalContacts $manage): void
    {
        $manage->disable($this->user(), $this->company(), $this->contact($contactId));
        $this->toast(__('Kontakten är avstängd och kan inte längre logga in.'));
    }

    public function remove(int $contactId, ManagePortalContacts $manage): void
    {
        $manage->remove($this->user(), $this->company(), $this->contact($contactId));
        $this->toast(__('Kontakten är borttagen från kundportalen.'));
    }

    public function render(): View
    {
        $contacts = PortalContact::query()->with('identity')->where('customer_id', $this->customerId)->orderBy('id')->get();

        return view('portal::livewire.customer-panel', [
            'customer' => Customer::query()->findOrFail($this->customerId),
            'contacts' => $contacts,
            'people' => $this->people(),
            'canManage' => $this->user()->hasTeamPermission($this->company(), 'customer-portal:manage'),
        ]);
    }

    /**
     * The CRM account's people with an address, the invoice recipients
     * first; empty while CRM is off.
     *
     * @return Collection<int, array{id: int, name: string, email: string, recipient: bool, blocked: bool}>
     */
    private function people(): Collection
    {
        if (! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $this->company())) {
            return collect();
        }

        $account = Account::query()->where('customer_id', $this->customerId)->first();

        if (! $account instanceof Account) {
            return collect();
        }

        $people = [];

        foreach (AccountPerson::query()->with('person')->where('account_id', $account->id)->get() as $link) {
            $person = $link->person;

            if ($person === null || $person->email === null) {
                continue;
            }

            $people[] = [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'recipient' => $link->role === PersonRole::InvoiceRecipient,
                'blocked' => $person->do_not_contact_at !== null,
            ];
        }

        return collect($people)->unique('id')->sortByDesc('recipient')->values();
    }

    private function contact(int $id): PortalContact
    {
        return PortalContact::query()->where('customer_id', $this->customerId)->findOrFail($id);
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
