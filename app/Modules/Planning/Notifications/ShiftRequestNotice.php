<?php

declare(strict_types=1);

namespace App\Modules\Planning\Notifications;

use App\Models\User;
use App\Modules\Planning\Mail\ShiftRequestNoticeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee how a planner answered a request about a shift, that a
 * colleague asks to swap with them (and how the colleague answered), or that
 * a swap with them was approved: in Notiser when the employee has a login,
 * and by email. Carries the company and the shifts as text.
 */
class ShiftRequestNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const string APPROVED = 'planning.request_approved';

    public const string REJECTED = 'planning.request_rejected';

    /** To the colleague whose shift was swapped. */
    public const string SWAPPED = 'planning.request_swapped';

    /** To the colleague a swap is asked of; actor is the employee asking. */
    public const string ASKED = 'planning.request_asked';

    /** To the employee asking: the colleague accepted; actor is the colleague. */
    public const string ACCEPTED = 'planning.request_accepted';

    /** To the employee asking: the colleague declined; actor is the colleague. */
    public const string DECLINED = 'planning.request_declined';

    /**
     * @param  list<array{day: string, time: string, area: ?string, change: string}>  $shifts
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $requestKind,
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly array $shifts,
        public readonly ?string $reason,
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

    public function toMail(object $notifiable): ShiftRequestNoticeMail
    {
        $mail = new ShiftRequestNoticeMail($this->sentence(), $this->companyName, $this->shifts, $this->reason);

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
            'title' => $this->sentence(),
            'actor' => $this->actor,
        ];
    }

    public function sentence(): string
    {
        $shift = trim(($this->shifts[0]['day'] ?? '').' '.($this->shifts[0]['time'] ?? ''));

        return match ($this->kind) {
            self::ASKED => __(':name vill byta pass med dig: ditt pass :yours mot :theirs. Svara på Min sida.', [
                'name' => $this->actor,
                'yours' => $shift,
                'theirs' => trim(($this->shifts[1]['day'] ?? '').' '.($this->shifts[1]['time'] ?? '')),
            ]),
            self::ACCEPTED => __(':name har sagt ja till att byta passet :shift. Nu väntar bytet på din chef.', ['name' => $this->actor, 'shift' => $shift]),
            self::DECLINED => __(':name tackade nej till att byta passet :shift.', ['name' => $this->actor, 'shift' => $shift]),
            self::SWAPPED => __('Ett pass är bytt med dig: du jobbar nu :shift.', ['shift' => $shift]),
            self::REJECTED => __('Din förfrågan om passet :shift är inte godkänd.', ['shift' => $shift]),
            default => match ($this->requestKind) {
                'CLAIM' => __('Du har fått passet :shift.', ['shift' => $shift]),
                'SWAP' => __('Bytet är godkänt: du jobbar nu :shift.', ['shift' => $shift]),
                default => __('Du är ledig från passet :shift.', ['shift' => $shift]),
            },
        };
    }
}
