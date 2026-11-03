<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/** What an activity is (ADR-043). E-mail is logged by hand in stage 1. */
enum ActivityType: string
{
    case Note = 'NOTE';
    case Call = 'CALL';
    case Meeting = 'MEETING';
    case Email = 'EMAIL';
    case Task = 'TASK';

    public function label(): string
    {
        return match ($this) {
            self::Note => __('Anteckning'),
            self::Call => __('Samtal'),
            self::Meeting => __('Möte'),
            self::Email => __('E-post'),
            self::Task => __('Uppgift'),
        };
    }

    /** A note is done when it is written; the others can be planned ahead. */
    public function canBePlanned(): bool
    {
        return $this !== self::Note;
    }
}
