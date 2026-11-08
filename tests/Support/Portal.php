<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Portal\Actions\ManagePortalContacts;
use App\Modules\Portal\Domain\ContactStatus;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\PortalModule;
use App\Modules\Portal\Support\PortalSession;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The customer portal in tests (ADR-075): the module on, customers,
 * invoices, contacts made ACTIVE through the real invitation, and a
 * signed-in portal session on the portal's own cookie.
 */
final class Portal
{
    public static function enable(Company $company, User $owner): void
    {
        config(['nonox.customer_portal' => true]);
        app(ModuleManager::class)->enable(PortalModule::KEY, $company, $owner);
        CurrentCompany::set($company);
    }

    public static function key(Company $company): string
    {
        return CurrentCompany::run($company, fn (): string => PortalSettings::current()->portal_key);
    }

    /** @param  array<string, mixed>  $extra */
    public static function customer(Company $company, User $owner, string $name = 'Kontorshuset AB', array $extra = []): Customer
    {
        return CurrentCompany::run($company, fn (): Customer => app(SaveCustomer::class)($owner, $company, [
            'name' => $name, 'org_number' => '5563343689', 'email' => 'ekonomi@kontorshuset.test',
            'address_line1' => 'Storgatan 1', 'postal_code' => '211 20', 'city' => 'Malmö', 'payment_terms_days' => 30, ...$extra,
        ]));
    }

    /**
     * An issued invoice for the customer.
     *
     * @param  list<array<string, mixed>>|null  $rows
     */
    public static function invoice(Company $company, User $owner, Customer $customer, string $date = '2026-06-01', ?array $rows = null): Invoice
    {
        return CurrentCompany::run($company, fn (): Invoice => app(IssueInvoice::class)($owner, app(SaveDraftInvoice::class)($owner, $company, [
            'customer_id' => $customer->id,
            'invoice_date' => $date,
            'rows' => $rows ?? [['description' => 'Konsulttimme', 'quantity' => '2', 'price' => '1000', 'vat_rate' => 2500]],
        ])));
    }

    /** An ACTIVE contact, invited the real way and accepted. */
    public static function contact(Company $company, User $owner, Customer $customer, string $email = 'anna@kontorshuset.test', string $name = 'Anna Andersson'): PortalContact
    {
        $invited = CurrentCompany::run($company, fn (): array => app(ManagePortalContacts::class)->invite($owner, $company, $customer, ['name' => $name, 'email' => $email]));

        return CurrentCompany::run($company, function () use ($invited): PortalContact {
            $contact = PortalContact::query()->findOrFail($invited['contact']->id);
            $contact->forceFill(['status' => ContactStatus::Active, 'accepted_at' => now()])->save();

            return $contact;
        });
    }

    /**
     * A signed-in portal session for the contact's identity, on the portal's
     * cookie: the first request switches the session to the portal's, then
     * the guard's session key is put in it as a sign-in would.
     */
    public static function signIn(TestCase $test, PortalContact $contact): PortalIdentity
    {
        $identity = PortalIdentity::query()->findOrFail($contact->portal_identity_id);
        $test->get('/kundportal');
        // A guard that has logged out stays logged out for its lifetime; a new request in production has a new one.
        Auth::forgetGuards();
        $guard = Auth::guard('portal');

        $test->withSession([
            $guard->getName() => $identity->id,
            PortalSession::STARTED => now()->getTimestamp(),
            PortalSession::LAST_ACTIVITY => now()->getTimestamp(),
            PortalSession::CONTACT => $contact->id,
        ]);

        return $identity;
    }
}
