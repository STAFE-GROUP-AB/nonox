<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Waitlist\OnboardingPlan;
use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * One of the two follow-up mails after approval (ADR-038 addendum): a
 * reminder to log in, or "Behöver du hjälp?" when a company exists but
 * nothing has been moved in. Each carries a signed one-click link that stops
 * the reminders, also as the List-Unsubscribe header mail clients show.
 */
class OnboardingReminderMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public const string LOGIN = 'login';

    public const string HELP = 'help';

    public function __construct(public readonly WaitlistEntry $entry, public readonly string $kind) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->kind === self::HELP ? __('Behöver du hjälp att komma igång i NONOX?') : __('Din inloggning till NONOX väntar'),
            replyTo: [(string) config('nonox.waitlist.reply_to')],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.onboarding-reminder', with: [
            'kind' => $this->kind,
            'firstName' => WaitlistApprovedMail::firstName($this->entry->name),
            'companyName' => $this->entry->company_name,
            'plan' => OnboardingPlan::forEntry($this->entry),
            'loginUrl' => route('login'),
            'unsubscribeUrl' => $this->unsubscribeUrl(),
            'signature' => (string) config('nonox.waitlist.signature'),
            'replyToAddress' => (string) config('nonox.waitlist.reply_to'),
            'fromAddress' => (string) config('mail.from.address'),
        ]);
    }

    /** A signed link with no expiry: opting out must keep working for as long as the entry exists. */
    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('waitlist.unsubscribe', ['entry' => $this->entry->id]);
    }
}
