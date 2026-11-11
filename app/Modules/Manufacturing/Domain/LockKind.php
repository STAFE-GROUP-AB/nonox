<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/**
 * A planner's hold on an operation (ADR-071 §13.6): LOCK keeps person,
 * workstation and start; ASSIGN keeps only the person (moved to another
 * qualified person); SPLIT gives some of the minutes to a second person.
 */
enum LockKind: string
{
    case Lock = 'LOCK';
    case Assign = 'ASSIGN';
    case Split = 'SPLIT';

    public function label(): string
    {
        return match ($this) {
            self::Lock => __('Låst'),
            self::Assign => __('Flyttad'),
            self::Split => __('Delad'),
        };
    }
}
