<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Portal\Domain\ThreadKind;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\PortalModule;

/**
 * Whether the portal's panels show on the company's own pages (the customer
 * card, the invoice): the module on, and the user may see the portal.
 */
final class PortalPanels
{
    /**
     * Whether the invoice has an open dispute from the portal, for a user
     * who may see the invoice (invoices:view); false while the module is off.
     */
    public static function disputed(Invoice $invoice, ?User $user): bool
    {
        $company = $user?->currentCompany();

        return $user instanceof User && $company instanceof Company
            && $company->id === $invoice->company_id
            && $user->hasTeamPermission($company, 'invoices:view')
            && app(ModuleManager::class)->isEnabled(PortalModule::KEY, $company)
            && PortalThread::query()->where('subject_type', PortalThread::INVOICE)->where('subject_id', $invoice->id)->where('kind', ThreadKind::Dispute->value)->where('status', PortalThread::OPEN)->exists();
    }

    public static function showFor(?User $user): bool
    {
        $company = $user?->currentCompany();

        return $user instanceof User && $company instanceof Company
            && app(ModuleManager::class)->isEnabled(PortalModule::KEY, $company)
            && $user->hasTeamPermission($company, 'customer-portal:view');
    }
}
