<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

/** The three kinds of meetings in the boardroom (ADR-070 §4). */
enum MeetingKind: string
{
    case AnnualGeneralMeeting = 'AGM';
    case ExtraGeneralMeeting = 'EXTRA_GM';
    case BoardMeeting = 'BOARD';

    public function label(): string
    {
        return match ($this) {
            self::AnnualGeneralMeeting => __('Årsstämma'),
            self::ExtraGeneralMeeting => __('Extra bolagsstämma'),
            self::BoardMeeting => __('Styrelsemöte'),
        };
    }

    public function isGeneralMeeting(): bool
    {
        return $this !== self::BoardMeeting;
    }
}
