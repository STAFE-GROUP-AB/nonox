<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * What a user may see of a shared group (ADR-060, addendum 2026-11-07, 2.2),
 * decided live by GroupAccess::levelFor() on every use.
 */
enum GroupAccessLevel: string
{
    /** reports:view in every member company: per-company figures, tags, pairs and drill-down. */
    case Full = 'FULL';

    /** A group role and still a member of the parent: the consolidated statements only. */
    case Consolidated = 'CONSOLIDATED';

    /** Nothing: the group answers 404 without its name, like any record of another tenant. */
    case None = 'NONE';

    public function sees(): bool
    {
        return $this !== self::None;
    }
}
