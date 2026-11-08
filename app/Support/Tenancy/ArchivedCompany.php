<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * An archived company can only be read (AGENTS.md §3.5, ADR-024). Two
 * layers enforce it:
 *
 * 1. User::hasTeamPermission grants only the permissions in READ while the
 *    company is archived, so every policy, action, page and API endpoint
 *    that asks for a write permission is refused, and write buttons that
 *    are shown by permission disappear.
 * 2. BelongsToCompany refuses to create or change a company-scoped row of
 *    an archived company, for code that writes without asking for a
 *    permission (scheduled commands, jobs, kiosks).
 *
 * Deleting is not refused by layer 2: posted vouchers are protected by the
 * database triggers anyway, users cannot delete anything without a write
 * permission, and the retention clean-up (privacy:prune, inbox:prune) must
 * keep removing personal data that has outlived its purpose.
 */
final class ArchivedCompany
{
    /**
     * What an archived company still allows: reading, reports and exports,
     * and the owner's permission to take the company out of the archive.
     */
    public const array READ = [
        'vouchers:view', 'invoices:view', 'reports:view', 'audit-log:view',
        'export:sie', 'export:archive', 'company:archive',
        'payroll:view', 'payroll:view-sensitive', 'payroll:archive-view', 'self:payslips',
        'staff-ledger:view', 'staff-ledger:view-sensitive',
        'inbox:view',
        'mileage:view',
    ];

    public static function isArchived(Company|int|null $company): bool
    {
        if ($company === null) {
            return false;
        }

        if ($company instanceof Company) {
            return $company->isArchived();
        }

        $context = self::context();

        if (! array_key_exists($company, $context->archived)) {
            $context->archived[$company] = DB::table('teams')->where('id', $company)->whereNotNull('archived_at')->exists();
        }

        return $context->archived[$company];
    }

    /** Whether the permission is granted in an archived company. */
    public static function allows(string $permission): bool
    {
        return self::context()->archiveReadingDepth > 0 || in_array($permission, self::READ, true);
    }

    /**
     * Checks permissions inside the callback as if the company were not
     * archived. Only for operations that read but are guarded by a write
     * permission: the data-subject export (registerutdrag) of a customer,
     * supplier, employee or inbox sender, which GDPR art. 15 requires for
     * as long as the data is kept.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function reading(callable $callback): mixed
    {
        $context = self::context();
        $context->archiveReadingDepth++;

        try {
            return $callback();
        } finally {
            $context->archiveReadingDepth--;
        }
    }

    /**
     * Lets the retention clean-up change rows of archived companies, for
     * example clearing a stale access token or moving the staff ledger's
     * chain anchor after old entries are removed.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function maintenance(callable $callback): mixed
    {
        $context = self::context();
        $context->archiveMaintenanceDepth++;

        try {
            return $callback();
        } finally {
            $context->archiveMaintenanceDepth--;
        }
    }

    /** Refuses a write to a company-scoped row of an archived company. */
    public static function guardWrite(object $model, mixed $companyId): void
    {
        if ($model instanceof WritableWhenArchived || ! is_numeric($companyId) || self::context()->archiveMaintenanceDepth > 0) {
            return;
        }

        if (self::isArchived((int) $companyId)) {
            throw CompanyIsArchived::whileWriting($model::class);
        }
    }

    /** Forget what is known about a company, after it was archived or taken out of the archive. */
    public static function forget(int $companyId): void
    {
        unset(self::context()->archived[$companyId]);
    }

    private static function context(): CompanyContext
    {
        return app(CompanyContext::class);
    }
}
