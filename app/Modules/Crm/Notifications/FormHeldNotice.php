<?php

declare(strict_types=1);

namespace App\Modules\Crm\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notice to those with crm:settings that web form submissions
 * are being held for review, or were dropped at the ceiling (SubmitForm,
 * ADR-048). At most one of each kind per company and day. Stored in the
 * database only, like SigningNotice, with the company's id so that opening
 * it goes through the company switch. Names no submitter.
 */
class FormHeldNotice extends Notification
{
    public const string KIND = 'crm_form_held';

    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly string $sentence,
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
            'title' => $this->sentence,
            'destination' => 'crm-forms',
            'destination_label' => __('Öppna Webbformulär'),
        ];
    }
}
