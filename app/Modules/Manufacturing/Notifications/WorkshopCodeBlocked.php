<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notice to those who set up the workshop terminal that someone's
 * code is blocked after repeated wrong guesses (ADR-071 §7). It stays
 * blocked until a manager sets a new code under Verkstadsterminal. Stored in
 * the database only.
 */
class WorkshopCodeBlocked extends Notification
{
    public const string KIND = 'workshop_code_blocked';

    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly int $personId,
        public readonly string $title,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => self::KIND,
            'company_id' => $this->companyId,
            'company_name' => $this->companyName,
            'person_id' => $this->personId,
            'title' => $this->title,
            'destination' => 'workshop-terminal',
            'destination_label' => __('Öppna Verkstadsterminal'),
        ];
    }
}
