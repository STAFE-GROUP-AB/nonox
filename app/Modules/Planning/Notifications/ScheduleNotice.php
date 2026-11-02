<?php

declare(strict_types=1);

namespace App\Modules\Planning\Notifications;

use App\Models\User;
use App\Modules\Planning\Mail\ScheduleNoticeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee that their schedule was published or that a published
 * shift was cancelled: in the app (Notiser) when the employee has a login,
 * and by email when an address is known. The notice carries the company's
 * id and name and the employee's own shifts as text, nothing else.
 */
class ScheduleNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const string PUBLISHED = 'planning.published';

    public const string CANCELLED = 'planning.cancelled';

    /**
     * @param  list<array{day: string, time: string, area: ?string, change: string}>  $shifts  the employee's shifts in the period, as shown
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly string $period,
        public readonly array $shifts,
        public readonly string $actor,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return $notifiable instanceof User && $notifiable->email !== '' ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): ScheduleNoticeMail
    {
        $mail = new ScheduleNoticeMail($this->kind, $this->companyName, $this->period, $this->shifts);

        if ($notifiable instanceof AnonymousNotifiable) {
            return $mail->to((string) $notifiable->routeNotificationFor('mail'));
        }

        return $mail->to($notifiable instanceof User ? $notifiable->email : '');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'company_id' => $this->companyId,
            'company_name' => $this->companyName,
            // The notice list shows the title of a kind it does not know.
            'title' => $this->sentence(),
            'actor' => $this->actor,
            'period' => $this->period,
        ];
    }

    public function sentence(): string
    {
        $count = count($this->shifts);

        return $this->kind === self::CANCELLED
            ? __('Ett av dina pass är inställt: :shift.', ['shift' => trim(($this->shifts[0]['day'] ?? '').' '.($this->shifts[0]['time'] ?? ''))])
            : trans_choice('{0} Schemat för :period är publicerat. Du har inga pass.|{1} Schemat för :period är publicerat. Du har 1 pass.|[2,*] Schemat för :period är publicerat. Du har :count pass.', $count, ['period' => $this->period, 'count' => $count]);
    }
}
