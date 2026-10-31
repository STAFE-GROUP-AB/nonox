<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Domain;

/**
 * Where a received message is. Quarantine and review are the tab
 * "Att granska", a ready proposal is "Utkast klara", booked and handed over
 * as drafts are "Bokfört", rejected is "Avvisat".
 */
enum MessageStatus: string
{
    case Quarantine = 'QUARANTINE';
    case NeedsReview = 'NEEDS_REVIEW';
    case DraftReady = 'DRAFT_READY';
    case Booked = 'BOOKED';
    case DraftCreated = 'DRAFT_CREATED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Quarantine => __('I karantän'),
            self::NeedsReview => __('Att granska'),
            self::DraftReady => __('Utkast klart'),
            self::Booked => __('Bokfört'),
            self::DraftCreated => __('Utkast skapat'),
            self::Rejected => __('Avvisat'),
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Quarantine, self::NeedsReview, self::DraftReady], true);
    }

    /** @return array<string, list<self>> tab key (Swedish, used in the URL) => statuses */
    public static function tabs(): array
    {
        return [
            'att-granska' => [self::Quarantine, self::NeedsReview],
            'utkast-klara' => [self::DraftReady],
            'bokfort' => [self::Booked, self::DraftCreated],
            'avvisat' => [self::Rejected],
        ];
    }
}
