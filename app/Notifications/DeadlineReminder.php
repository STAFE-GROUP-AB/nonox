<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Models\Company;
use Illuminate\Notifications\Notification;

/**
 * An in-app notice that a date on Mina datum is near (ADR-051, stage 2). Stored in the database only; it opens Mina datum
 * in that company. Sent once per person, deadline and day count
 * (App\Models\DeadlineReminder, guide:send-deadline-reminders).
 */
class DeadlineReminder extends Notification
{
    public const string KIND = 'deadline_reminder';

    /** Dates that are also a payment, which counts when it is booked on the tax account (SFL 62 kap. 2 §). */
    private const array PAYMENTS = [DeadlineType::Vat, DeadlineType::Agi, DeadlineType::PreliminaryTax];

    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly string $deadlineKey,
        public readonly string $title,
    ) {}

    public static function for(Company $company, Deadline $deadline, int $daysLeft): self
    {
        $replace = ['title' => $deadline->title, 'days' => $daysLeft, 'date' => $deadline->date->translatedFormat('j F')];

        return new self(
            $company->id,
            (string) $company->name,
            $deadline->key,
            match (true) {
                $daysLeft <= 0 => __(':title: sista dagen är i dag, :date.', $replace),
                $daysLeft === 1 => __(':title: sista dagen är i morgon, :date.', $replace),
                default => __(':title: sista dagen är om :days dagar, :date.', $replace),
            }.(in_array($deadline->type, self::PAYMENTS, true) ? ' '.__('Betala i god tid: en betalning räknas när den har kommit in på skattekontot.') : ''),
        );
    }

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
            'deadline_key' => $this->deadlineKey,
            'title' => $this->title,
            'destination' => 'mina-datum',
            'destination_label' => __('Öppna Mina datum'),
        ];
    }
}
