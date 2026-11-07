<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * An explicit role in a shared group (ADR-060, addendum 2026-11-07, 2.2;
 * decision D2: one role in stage 1). A group role is never a membership in
 * a company and grants no permission string in any company.
 */
enum GroupRole: string
{
    /** Sees the consolidated figures only, while still a member of the parent. */
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => __('Läser koncernrapporten'),
        };
    }
}
