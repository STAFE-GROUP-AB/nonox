<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalSettings;
use LogicException;

/**
 * The portal a contact is in (ADR-075 §8): the company and the customer,
 * both taken from the contact the session chose, never from the request.
 * Every portal query is scoped to the company (CurrentCompany, set from
 * here) and to the customer (the queries in PortalDocuments). Scoped to the
 * request; empty outside the portal's own pages.
 */
final class PortalContext
{
    private ?PortalIdentity $identity = null;

    private ?PortalContact $contact = null;

    private ?Company $company = null;

    private ?Customer $customer = null;

    private ?PortalSettings $settings = null;

    public function set(PortalIdentity $identity, PortalContact $contact, Company $company, Customer $customer, PortalSettings $settings): void
    {
        if ($contact->portal_identity_id !== $identity->id || $contact->company_id !== $company->id || $contact->customer_id !== $customer->id || $settings->company_id !== $company->id) {
            throw new LogicException('A portal context must hold one contact of one customer of one company.');
        }

        [$this->identity, $this->contact, $this->company, $this->customer, $this->settings] = [$identity, $contact, $company, $customer, $settings];
    }

    public function has(): bool
    {
        return $this->contact !== null;
    }

    public function identity(): PortalIdentity
    {
        return $this->identity ?? throw new LogicException('No portal context.');
    }

    public function contact(): PortalContact
    {
        return $this->contact ?? throw new LogicException('No portal context.');
    }

    public function company(): Company
    {
        return $this->company ?? throw new LogicException('No portal context.');
    }

    public function customer(): Customer
    {
        return $this->customer ?? throw new LogicException('No portal context.');
    }

    public function settings(): PortalSettings
    {
        return $this->settings ?? throw new LogicException('No portal context.');
    }

    public function key(): string
    {
        return $this->settings()->portal_key;
    }
}
