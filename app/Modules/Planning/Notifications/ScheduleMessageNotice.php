<?php

declare(strict_types=1);

namespace App\Modules\Planning\Notifications;

use App\Models\User;
use App\Modules\Planning\Mail\ScheduleMessageMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee with a login that there is a new message about the
 * schedule (ADR-037 gap 5): in Notiser and by e-mail. It carries the
 * company's id and name and whether a confirmation is asked for, never the
 * message text, so no message text leaves NONOX by e-mail and none is copied
 * into the notifications table. The text is read on Min sida.
 */
class ScheduleMessageNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const string KIND = 'planning.message';

    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly bool $requiresAck,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->email !== '' ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): ScheduleMessageMail
    {
        return (new ScheduleMessageMail($this->companyName, $this->requiresAck))->to($notifiable instanceof User ? $notifiable->email : '');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => self::KIND,
            'company_id' => $this->companyId,
            'company_name' => $this->companyName,
            'title' => $this->sentence(),
            'destination' => 'my-page',
            'destination_label' => __('Läs på Min sida'),
        ];
    }

    public function sentence(): string
    {
        return $this->requiresAck
            ? __('Du har ett nytt meddelande om schemat. Läs det och bekräfta på Min sida.')
            : __('Du har ett nytt meddelande om schemat. Läs det på Min sida.');
    }
}
