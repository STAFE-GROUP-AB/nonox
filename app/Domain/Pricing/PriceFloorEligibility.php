<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Approval\DraftEditors;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PriceFloorApproval;
use App\Models\SalesDocument;
use App\Models\User;

/**
 * Who may decide a price floor approval (ADR-082 §9): someone with
 * prices:approve-below-floor, who did not write the document, change its
 * rows or ask for the approval. The last rule holds while the company
 * requires separate approval (AGENTS.md §4, on by default), the same switch
 * as for supplier invoices and payments, so a one-person company can turn it
 * off and approve its own prices.
 */
final class PriceFloorEligibility
{
    public const string NOT_APPROVER = 'not_approver';

    public const string WROTE_IT = 'wrote_it';

    /**
     * Why the user may not decide the approval, or null when they may.
     */
    public static function refusal(User $user, Company $company, Invoice|SalesDocument $document, PriceFloorApproval $approval): ?string
    {
        if (! $user->hasTeamPermission($company, 'prices:approve-below-floor')) {
            return self::NOT_APPROVER;
        }

        if (! $company->separate_supplier_approval) {
            return null;
        }

        $preparers = [$document->created_by, $approval->requested_by];

        $actions = $document instanceof Invoice ? DraftEditors::INVOICE_ACTIONS : DraftEditors::SALES_DOCUMENT_ACTIONS;
        $preparers = [...$preparers, ...(DraftEditors::of($company->id, $document->getMorphClass(), [$document->id], $actions)[$document->id] ?? [])];

        return in_array($user->id, $preparers, true) ? self::WROTE_IT : null;
    }
}
