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
use Illuminate\Support\Facades\URL;

/**
 * One of the two follow-up mails after approval (ADR-038 addendum): a
 * reminder to log in, or "Behöver du hjälp?" when a company exists but
 * nothing has been moved in. Each carries a signed one-click link that stops
 * the reminders, also as the List-Unsubscribe header mail clients show.
 *
 * The mail keeps plain values, not the entry, so it still works if the entry
 * is deleted before the queue picks it up. Its properties are named so that
 * they do not clash with the view's variables: a public property overrides the
 * view data of the same name, and the view gets the escaped values.
 */
class OnboardingReminderMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public const string LOGIN = 'login';

    public const string HELP = 'help';

    public readonly int $entryId;

    public readonly string $applicantName;

    public readonly string $forCompany;

    public readonly OnboardingPlan $plan;

    public function __construct(WaitlistEntry $entry, public readonly string $kind)
    {
        $this->entryId = $entry->id;
        $this->applicantName = $entry->name;
        $this->forCompany = $entry->company_name;
        $this->plan = OnboardingPlan::forEntry($entry);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->kind === self::HELP ? __('Behöver du hjälp att komma igång i NONOX?') : __('Din inloggning till NONOX väntar'),
            replyTo: [OnboardingPlan::replyTo()],
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
        $escape = OnboardingPlan::escapeMarkdown(...);

        return new Content(markdown: 'emails.onboarding-reminder', with: [
            'kind' => $this->kind,
            'firstName' => $escape(WaitlistApprovedMail::firstName($this->applicantName)),
            'companyName' => $escape($this->forCompany),
            'plan' => $this->plan,
            'loginUrl' => route('login'),
            'unsubscribeUrl' => $this->unsubscribeUrl(),
            'signature' => $escape(OnboardingPlan::signature()),
            'replyToAddress' => OnboardingPlan::replyTo(),
            'fromAddress' => (string) config('mail.from.address'),
        ]);
    }

    /**
     * A signed link with no expiry: opting out must keep working for as long
     * as the entry exists. When the queue worker builds the mail the link is
     * made for the app's URL (APP_URL).
     */
    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('waitlist.unsubscribe', ['entry' => $this->entryId]);
    }
}
