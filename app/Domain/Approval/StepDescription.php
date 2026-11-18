<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Support\Money\Money;

/**
 * A step in plain Swedish, for the policy page and the subject's approval
 * card: who decides, how many, and when the step applies. Names come from
 * maps the page has already loaded, so describing makes no queries.
 */
final class StepDescription
{
    public static function permission(?string $permission): string
    {
        return match ($permission) {
            'supplier-invoices:approve' => __('alla med attesträtt för leverantörsfakturor'),
            'payments:approve' => __('alla med rätt att godkänna betalningar'),
            'purchase-orders:send' => __('alla med rätt att skicka inköpsorder'),
            'approvals:manage' => __('ägare och administratörer'),
            default => (string) $permission,
        };
    }

    /** @param  array<int|string, string>  $userNames */
    public static function approvers(SnapshotStep $step, array $userNames): string
    {
        $who = match ($step->kind) {
            ApproverKind::Permission => self::permission($step->permission),
            ApproverKind::Users => implode(', ', array_map(fn (int $id): string => $userNames[$id] ?? __('tidigare medlem'), $step->userIds)),
            ApproverKind::DimensionOwner => $step->dimension === ApprovalDimension::Project
                ? __('projektets ägare')
                : __('kostnadsställets ägare'),
        };

        return $step->requiredCount > 1
            ? __(':count av :who', ['count' => $step->requiredCount, 'who' => $who])
            : $who;
    }

    /**
     * @param  array<int|string, string>  $costCenters
     * @param  array<int|string, string>  $projects
     * @param  array<int|string, string>  $suppliers
     */
    public static function conditions(StepConditions $conditions, array $costCenters = [], array $projects = [], array $suppliers = []): string
    {
        $parts = [];

        if ($conditions->minOre !== null && $conditions->maxOre !== null) {
            $parts[] = __('belopp :min–:max', ['min' => Money::ofOre($conditions->minOre)->format(), 'max' => Money::ofOre($conditions->maxOre)->format()]);
        } elseif ($conditions->minOre !== null) {
            $parts[] = __('belopp från :min', ['min' => Money::ofOre($conditions->minOre)->format()]);
        } elseif ($conditions->maxOre !== null) {
            $parts[] = __('belopp upp till :max', ['max' => Money::ofOre($conditions->maxOre)->format()]);
        }

        foreach ($conditions->costCenterIds as $id) {
            $parts[] = __('kostnadsställe :name', ['name' => $costCenters[$id] ?? $id]);
        }

        foreach ($conditions->projectIds as $id) {
            $parts[] = __('projekt :name', ['name' => $projects[$id] ?? $id]);
        }

        foreach ($conditions->supplierIds as $id) {
            $parts[] = __('leverantör :name', ['name' => $suppliers[$id] ?? $id]);
        }

        if ($conditions->accountFrom !== null || $conditions->accountTo !== null) {
            $parts[] = __('konto :from–:to', ['from' => $conditions->accountFrom ?? 1000, 'to' => $conditions->accountTo ?? 9999]);
        }

        return $parts === [] ? __('alltid') : implode(', ', $parts);
    }
}
