<?php

declare(strict_types=1);

namespace App\Domain\Approval;

/** Who may decide a step (ADR-081 gap 8). */
enum ApproverKind: string
{
    case Permission = 'PERMISSION';
    case Users = 'USERS';
    case DimensionOwner = 'DIMENSION_OWNER';

    public function label(): string
    {
        return match ($this) {
            self::Permission => __('Alla med en behörighet'),
            self::Users => __('Utvalda personer'),
            self::DimensionOwner => __('Ägaren av kostnadsstället eller projektet'),
        };
    }

    /**
     * A step that names people can be passed on to a substitute. A step that
     * names a permission cannot: everyone else with the permission can
     * decide it, and a substitute must never gain a permission that way.
     */
    public function allowsDelegation(): bool
    {
        return $this !== self::Permission;
    }
}
