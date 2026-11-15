<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notice to the members with saas:manage that Stripe no longer
 * accepts the company's key, so the connection has ended (ADR-080 §7.7).
 * Stored in the database only, with the company's id so that opening it goes
 * through the company switch. No key, no account id.
 */
class StripeRevokedNotice extends Notification
{
    public const string KIND = 'stripe_revoked';

    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
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
            'title' => __('Stripe godtar inte längre nyckeln för :company. Kopplingen är avslutad; koppla Stripe igen med en ny begränsad nyckel.', ['company' => $this->companyName]),
            'destination' => 'saas-stripe',
            'destination_label' => __('Öppna Stripe'),
        ];
    }
}
