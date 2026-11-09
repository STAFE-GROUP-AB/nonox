<?php

declare(strict_types=1);

namespace App\Modules\Saas\Actions;

use App\Models\Company;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Modules\Crm\Models\Agreement;
use App\Modules\ModuleManager;
use App\Modules\Saas\Models\SaasSeparatePair;
use App\Modules\Saas\SaasModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * "Markera som separata avtal" under Källor (re-check P3): a person with
 * saas:manage confirms that an agreement in the register and an
 * avtalsfakturering of the same customer are different agreements, so they
 * are no longer listed as possible double counting. Stored as the two ids
 * only, audit-logged, and undone by removing the marking. Both agreements
 * must belong to the company (another company's id answers 404).
 */
final class MarkSeparateAgreements
{
    public function mark(User $user, Company $company, int $crmAgreementId, string $recurringInvoiceId): SaasSeparatePair
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, fn (): SaasSeparatePair => DB::transaction(function () use ($user, $company, $crmAgreementId, $recurringInvoiceId): SaasSeparatePair {
            Agreement::query()->findOrFail($crmAgreementId);
            RecurringInvoice::query()->findOrFail($recurringInvoiceId);

            $pair = SaasSeparatePair::query()->firstOrCreate(
                ['crm_agreement_id' => $crmAgreementId, 'recurring_invoice_id' => $recurringInvoiceId],
                ['created_by' => $user->id],
            );

            Audit::record('saas.agreements_marked_separate', $pair, ['crm_agreement_id' => $crmAgreementId, 'recurring_invoice_id' => $recurringInvoiceId], $company, $user);

            return $pair;
        }));
    }

    public function undo(User $user, Company $company, int $pairId): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $pairId): void {
            $pair = SaasSeparatePair::query()->findOrFail($pairId);

            Audit::record('saas.agreements_unmarked_separate', $pair, ['crm_agreement_id' => $pair->crm_agreement_id, 'recurring_invoice_id' => $pair->recurring_invoice_id], $company, $user);

            $pair->delete();
        }));
    }

    private function authorize(User $user, Company $company): void
    {
        app(ModuleManager::class)->ensureEnabled(SaasModule::KEY, $company);

        if (! $user->hasTeamPermission($company, SaasModule::MANAGE)) {
            throw new AuthorizationException(__('Du har inte behörighet att ändra underlaget för återkommande intäkter.'));
        }
    }
}
