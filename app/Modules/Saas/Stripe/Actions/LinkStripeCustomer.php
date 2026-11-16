<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Actions;

use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Saas\Jobs\RecomputeSaas;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Stripe\Support\StripeLedger;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person's decisions about a Stripe customer (ADR-080 §7.5), each with
 * saas:manage, audit-logged with ids and enums only:
 *
 * - link() to a NONOX customer (a suggestion, or one the person picks);
 * - confirm() a link a rule made;
 * - unlink(): it counts as its own provisional customer again and is not
 *   matched automatically any more;
 * - createCustomer(): "Skapa kund", a NONOX customer from the Stripe
 *   customer's name, e-mail, address and Swedish VAT number, linked.
 *
 * The subscriptions move with the customer from their first day: closed
 * months keep their figures, and the difference is booked as Rättelse in the
 * first open month by the recompute that follows.
 */
class LinkStripeCustomer
{
    public function __construct(private readonly StripeLedger $ledger) {}

    public function link(User $user, Company $company, StripeCustomerLink $link, Customer $customer, ?string $method = null): StripeCustomerLink
    {
        StripePermissions::authorizeManage($user, $company);

        return $this->decide($user, $company, $link, [
            'status' => StripeCustomerLink::LINKED,
            'customer_id' => $customer->id,
            'method' => $method ?? ($link->suggested_customer_id === $customer->id ? $link->suggestion_method : StripeCustomerLink::BY_PERSON),
        ], 'stripe.customer_linked');
    }

    public function confirm(User $user, Company $company, StripeCustomerLink $link): StripeCustomerLink
    {
        StripePermissions::authorizeManage($user, $company);

        if (! $link->isLinked()) {
            throw ValidationException::withMessages(['link' => __('Stripe-kunden är inte kopplad.')]);
        }

        return $this->decide($user, $company, $link, [], 'stripe.customer_link_confirmed');
    }

    public function unlink(User $user, Company $company, StripeCustomerLink $link): StripeCustomerLink
    {
        StripePermissions::authorizeManage($user, $company);

        return $this->decide($user, $company, $link, [
            'status' => StripeCustomerLink::UNLINKED,
            'customer_id' => null,
            'method' => null,
            'suggested_customer_id' => null,
            'suggestion_method' => null,
        ], 'stripe.customer_unlinked');
    }

    /**
     * Erases a Stripe customer that is not linked, on the person's request
     * (GDPR art. 17, review P2-2): its details are cleared and never read in
     * again, and its raw subscriptions are deleted; it still counts under its
     * provisional id with ids and amounts only. A linked Stripe customer is
     * erased with its customer in the customer register. Works also while
     * Stripe is switched off.
     */
    public function erase(User $user, Company $company, StripeCustomerLink $link): void
    {
        if (! $user->hasTeamPermission($company, SaasModule::MANAGE)) {
            throw new AuthorizationException(__('Du har inte behörighet att hantera återkommande intäkter.'));
        }

        if ($link->isLinked()) {
            throw ValidationException::withMessages(['link' => __('Stripe-kunden är kopplad till en kund. Radera eller anonymisera kunden i kundregistret i stället.')]);
        }

        CurrentCompany::run($company, fn () => DB::transaction(fn () => PruneStripe::erase($link)));
        Audit::record('stripe.customer_erased', $link, ['link' => $link->id], $company, $user);
    }

    public function createCustomer(User $user, Company $company, StripeCustomerLink $link): Customer
    {
        StripePermissions::authorizeManage($user, $company);

        if ($link->isErased()) {
            throw ValidationException::withMessages(['link' => __('Stripe-kundens uppgifter är raderade.')]);
        }

        if ($link->isLinked()) {
            throw ValidationException::withMessages(['link' => __('Stripe-kunden är redan kopplad till en kund.')]);
        }

        $address = (array) $link->address;
        $vat = null;
        $org = null;

        foreach ((array) $link->tax_ids as $taxId) {
            $value = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $taxId['value']));

            if ($taxId['type'] === 'eu_vat' && preg_match('/^[A-Z]{2}[0-9A-Z]{2,18}$/', $value) === 1) {
                $vat ??= $value;
                $org ??= preg_match('/^SE(\d{10})01$/', $value, $match) === 1 ? OrganisationNumber::tryFrom($match[1]) : null;
            }
        }

        $email = filter_var((string) $link->email, FILTER_VALIDATE_EMAIL) === false ? null : $link->email;

        return DB::transaction(function () use ($user, $company, $link, $address, $vat, $org, $email): Customer {
            $customer = app(SaveCustomer::class)($user, $company, array_filter([
                'name' => $link->label(),
                'email' => $email,
                'vat_number' => $vat,
                // A sole trader's number is a personal number and is not copied as an organisation number.
                'org_number' => $org !== null && ! $org->isPersonalIdentityNumber() ? $org->formatted() : null,
                'address_line1' => $address['line1'] ?? null,
                'address_line2' => $address['line2'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                'city' => $address['city'] ?? null,
                'country' => $link->country,
                'payment_terms_days' => 30,
            ], fn (mixed $value): bool => $value !== null && $value !== ''));

            $this->link($user, $company, $link, $customer, StripeCustomerLink::BY_CREATED);

            return $customer;
        });
    }

    /** @param  array<string, mixed>  $changes */
    private function decide(User $user, Company $company, StripeCustomerLink $link, array $changes, string $action): StripeCustomerLink
    {
        return CurrentCompany::run($company, function () use ($user, $company, $link, $changes, $action): StripeCustomerLink {
            $moved = DB::transaction(function () use ($user, $link, $changes): int {
                $link->forceFill([...$changes, 'confirmed_by' => $user->id, 'confirmed_at' => now()])->save();

                return $this->ledger->follow($link, $user->id);
            });

            Audit::record($action, $link, ['link' => $link->id, 'customer' => $link->customer_id, 'method' => $link->method, 'versions' => $moved], $company, $user);

            if ($moved > 0) {
                RecomputeSaas::dispatch($company->id)->afterCommit();
            }

            return $link;
        });
    }
}
