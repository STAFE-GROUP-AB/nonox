<?php

declare(strict_types=1);

namespace App\Modules\Portal\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notice that a customer wrote in the portal or said an invoice
 * is paid (ADR-075 §5, §6), to the users with customer-portal:reply. Stored in the database only, like the
 * task notices (ADR-028), with the company's id so that opening it goes
 * through the company switch. Names neither the customer nor the text.
 */
class PortalQuestionNotice extends Notification
{
    public const string KIND = 'customer_portal_question';

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
            'destination' => 'customer-portal-questions',
            'destination_label' => __('Öppna Frågor från kunder'),
        ];
    }
}
