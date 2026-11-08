<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notice that a quote or an agreement was signed or declined
 * (ADR-046), to the person who sent it and the deal's or agreement's owner.
 * Stored in the database only, like the task notices (ADR-028), with the
 * company's id so that opening it goes through the company switch. The
 * sentence names the document, not the signer.
 */
class SigningNotice extends Notification
{
    public const string KIND = 'crm_signing';

    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly string $sentence,
        public readonly string $destination,
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
            // The list shows 'title' as the sentence (TaskActivity::sentence's default).
            'title' => $this->sentence,
            'destination' => $this->destination,
            'destination_label' => $this->destination === 'quotes' ? __('Öppna Offerter') : __('Öppna Avtal'),
        ];
    }
}
