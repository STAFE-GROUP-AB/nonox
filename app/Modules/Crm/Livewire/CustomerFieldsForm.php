<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Actions\Invoicing\SaveCustomer;
use App\Livewire\Invoicing\Customers;
use App\Models\Customer;
use App\Models\User;
use App\Models\VatNumberCheck;
use App\Modules\Crm\Models\Account;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;

/**
 * The ledger fields of a customer on its CRM card (ADR-043): the customer
 * register's own form and logic (SaveCustomer, VIES, GDPR restriction and
 * erasure), always open for the one customer, for those who may change
 * customers.
 */
class CustomerFieldsForm extends Customers
{
    #[Locked]
    public int $customerId;

    public function mount(?int $customerId = null): void
    {
        $customer = Customer::query()->findOrFail((int) $customerId);
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('update', $customer), 403);

        $this->customerId = $customer->id;
        $this->edit($customer->id);
    }

    /** Avbryt puts the saved values back; the form stays open. */
    public function cancel(): void
    {
        if (! Customer::query()->whereKey($this->customerId)->exists()) {
            Toast::flash(__('Kunden är raderad.'));
            $this->redirectRoute('invoicing.customers');

            return;
        }

        $this->edit($this->customerId);
    }

    public function save(SaveCustomer $save): void
    {
        parent::save($save);

        // The card shows the name, numbers and address too: reload it with the saved values.
        Toast::flash(__('Kunden :name är sparad.', ['name' => (string) Customer::query()->whereKey($this->customerId)->value('name')]));
        $account = Account::query()->where('customer_id', $this->customerId)->value('id');
        $account === null ? $this->redirectRoute('invoicing.customers') : $this->redirectRoute('crm.accounts.show', ['account' => $account]);
    }

    public function render(): View
    {
        return view('crm::livewire.customer-fields', [
            'nextNumber' => 0,
            'formTitle' => __('Kunduppgifter för fakturor'),
            'vatCheck' => VatNumberCheck::query()->where('customer_id', $this->customerId)->latest('checked_at')->latest('id')->first(),
        ]);
    }
}
