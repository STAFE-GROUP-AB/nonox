<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Models\CorporateGroup;
use App\Models\User;
use App\Support\Audit\Audit;

/**
 * Writes a shared-group change to the behandlingshistorik of every affected
 * company (ADR-060, addendum 2026-11-07, 2.2): one entry per company, with
 * that company's id, the group as subject and a payload of ids and enum
 * values that never names another member company. A company's auditors see
 * that their company was added, removed or given a viewer, and nothing about
 * the others.
 */
final class GroupAuditTrail
{
    /**
     * @param  iterable<int>  $companyIds  the affected companies
     * @param  array<string, int|string|bool|null>  $payload  ids and enum values only
     * @param  ?int  $concerned  the company the change is about, flagged in its own entry only
     */
    public static function record(string $action, CorporateGroup $group, iterable $companyIds, User $user, array $payload = [], ?int $concerned = null): void
    {
        $ids = [];

        foreach ($companyIds as $companyId) {
            $ids[$companyId] = true;
        }

        foreach (array_keys($ids) as $companyId) {
            Audit::record($action, $group, [
                'corporate_group_id' => $group->id,
                ...$payload,
                'this_company' => $concerned === $companyId,
            ], $companyId, $user);
        }
    }
}
