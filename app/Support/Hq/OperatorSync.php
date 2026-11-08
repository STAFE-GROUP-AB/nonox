<?php

declare(strict_types=1);

namespace App\Support\Hq;

use App\Jobs\SyncToOperator;
use App\Models\Company;
use App\Models\WaitlistEntry;
use App\Support\Demo\DemoMode;
use App\Support\Instance\InstanceSettings;

/**
 * When the installation's companies and queue applicants are copied to the
 * operator company (ADR-044). Called from the company's model events and
 * from JoinWaitlist; queues SyncToOperator after the commit, so the sync
 * can never fail or slow the user's own action, and it carries ids only
 * (AGENTS.md §6.0).
 */
final class OperatorSync
{
    /** @var list<string> the company fields the customer copies */
    public const array WATCHED = ['name', 'legal_name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'archived_at', 'user_id'];

    public static function companySaved(Company $company, bool $created): void
    {
        // A demo company (ADR-077) lives in the demo database and is nobody's customer.
        if (DemoMode::onDemoDatabase()) {
            return;
        }

        $target = InstanceSettings::current()->operatorCompanyId();

        if ($target === null || (! $created && ! $company->wasChanged(self::WATCHED))) {
            return;
        }

        if ($company->id === $target || $company->isTestCompany()) {
            return;
        }

        SyncToOperator::dispatch(SyncToOperator::COMPANY, $company->id);
    }

    public static function waitlistEntryCreated(WaitlistEntry $entry): void
    {
        if (InstanceSettings::current()->operatorCompanyId() === null) {
            return;
        }

        SyncToOperator::dispatch(SyncToOperator::WAITLIST, $entry->id);
    }

    /**
     * Which companies never become customers: the operator company itself
     * and test companies (demo data, ADR-032). An archived company is not
     * excluded here: a customer it already has is made inactive; it just
     * never becomes a new one.
     */
    public static function isExcluded(Company $company, Company $target): bool
    {
        return $company->id === $target->id || $company->isTestCompany();
    }
}
