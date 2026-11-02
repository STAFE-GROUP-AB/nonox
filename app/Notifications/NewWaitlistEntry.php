<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A notice to the HQ admins that someone joined the waiting list (ADR-038).
 * Stored in the database only; e-mail is the daily digest. It carries the
 * company name and the entry's id, not the applicant's personal details.
 */
class NewWaitlistEntry extends Notification
{
    public const string KIND = 'waitlist.new';

    public function __construct(public readonly int $entryId, public readonly string $companyName) {}

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
            'company_name' => 'NONOX HQ',
            'waitlist_entry_id' => $this->entryId,
            'title' => __('Ny i kön: :company', ['company' => $this->companyName]),
        ];
    }
}
