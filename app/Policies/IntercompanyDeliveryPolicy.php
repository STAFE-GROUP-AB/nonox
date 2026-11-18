<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Support\Facades\Gate;

/**
 * Intra-group transactions (ADR-081 gap 1, E27, "Permissions"):
 *
 * - delivering an invoice to a group company: invoices:send in the sender,
 *   the same as e-mailing it or sending it over Peppol (InvoicePolicy::send);
 * - the receipt setting: company:update in the receiver (owner, admin);
 * - a paired draft: vouchers:create in both companies, the source voucher
 *   seen with vouchers:view;
 * - the delivery log and pairs are read with invoices:view and
 *   vouchers:view.
 *
 * A member limited to cost centres or projects (gap 10) does none of this:
 * each of these spans companies or the company's whole invoicing.
 */
final class IntercompanyDeliveryPolicy
{
    public static function mayDeliver(User $user, Invoice $invoice): bool
    {
        return ! DimensionScope::limits($user, $invoice->company_id) && Gate::forUser($user)->allows('send', $invoice);
    }

    public static function mayManageReceipt(User $user, Company $company): bool
    {
        return ! DimensionScope::limits($user, $company) && $user->hasTeamPermission($company, 'company:update');
    }

    public static function mayViewDeliveries(User $user, Company $company): bool
    {
        return ! DimensionScope::limits($user, $company) && $user->hasTeamPermission($company, 'invoices:view');
    }

    public static function mayViewPairs(User $user, Company $company): bool
    {
        return ! DimensionScope::limits($user, $company) && $user->hasTeamPermission($company, 'vouchers:view');
    }

    public static function mayPair(User $user, Company $company): bool
    {
        return ! DimensionScope::limits($user, $company) && $user->hasTeamPermission($company, 'vouchers:create');
    }

    /** Whether the page Koncernleveranser has anything for the user. */
    public static function mayOpenPage(User $user, Company $company): bool
    {
        return self::mayManageReceipt($user, $company) || self::mayViewDeliveries($user, $company) || self::mayViewPairs($user, $company);
    }
}
