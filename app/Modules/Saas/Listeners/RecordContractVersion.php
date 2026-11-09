<?php

declare(strict_types=1);

namespace App\Modules\Saas\Listeners;

use App\Domain\Invoicing\AgreementStatus;
use App\Events\RecurringInvoiceChanged;
use App\Events\RecurringInvoiceStatusChanged;
use App\Models\RecurringInvoice;
use App\Modules\Crm\Events\CrmAgreementChanged;
use App\Modules\Crm\Models\Agreement;
use App\Modules\ModuleManager;
use App\Modules\Saas\Domain\VersionCause;
use App\Modules\Saas\Jobs\RecomputeSaas;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Support\ContractVersions;
use App\Support\Tenancy\CurrentCompany;

/**
 * Turns a change of an agreement into a contract version, in the same
 * transaction as the change, while NONOX SaaS is on for the company
 * (ADR-080 §3.1). The open months are recomputed after the commit.
 */
final class RecordContractVersion
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ContractVersions $versions,
    ) {}

    public function recurringInvoice(RecurringInvoiceChanged $event): void
    {
        $this->forCompany($event->companyId, function () use ($event): bool {
            $agreement = RecurringInvoice::query()->find($event->recurringInvoiceId);

            return $agreement instanceof RecurringInvoice
                && $this->versions->recordRecurringInvoice($agreement, $event->created ? VersionCause::Created : null, $event->effectiveFrom, $event->userId) !== null;
        });
    }

    public function recurringInvoiceStatus(RecurringInvoiceStatusChanged $event): void
    {
        $this->forCompany($event->companyId, function () use ($event): bool {
            $agreement = RecurringInvoice::query()->find($event->recurringInvoiceId);

            if (! $agreement instanceof RecurringInvoice) {
                return false;
            }

            $cause = match (AgreementStatus::from($event->status)) {
                AgreementStatus::Paused => VersionCause::Paused,
                AgreementStatus::Active => VersionCause::Resumed,
                AgreementStatus::Ended => VersionCause::Ended,
            };

            return $this->versions->recordRecurringInvoice($agreement, $cause, $event->effectiveFrom, $event->userId) !== null;
        });
    }

    public function crmAgreement(CrmAgreementChanged $event): void
    {
        $this->forCompany($event->companyId, function () use ($event): bool {
            $agreement = Agreement::query()->find($event->agreementId);

            return $agreement instanceof Agreement
                && $this->versions->recordCrmAgreement($agreement, $event->created ? VersionCause::Created : null, $event->userId) !== null;
        });
    }

    /** @param  callable(): bool  $record  true when a version was written */
    private function forCompany(int $companyId, callable $record): void
    {
        if (! $this->modules->isEnabled(SaasModule::KEY, $companyId)) {
            return;
        }

        if (CurrentCompany::run($companyId, $record)) {
            RecomputeSaas::dispatch($companyId)->afterCommit();
        }
    }
}
