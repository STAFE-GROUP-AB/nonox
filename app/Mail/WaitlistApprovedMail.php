<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Company\Industry;
use App\Domain\Waitlist\OnboardingPlan;
use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells someone on the waiting list that they may log in now, in a personal
 * mail built from what they answered on the waiting list (ADR-038 addendum):
 * their name and company, where they come from, whether they have employees
 * and what the company does. Sent from the instance's normal address; replies
 * go to the person who signs it.
 */
class WaitlistApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly WaitlistEntry $entry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Du är godkänd – logga in i NONOX'),
            replyTo: [(string) config('nonox.waitlist.reply_to')],
        );
    }

    public function content(): Content
    {
        $plan = OnboardingPlan::forEntry($this->entry);

        return new Content(markdown: 'emails.waitlist-approved', with: [
            'firstName' => self::firstName($this->entry->name),
            'companyName' => $this->entry->company_name,
            'email' => $this->entry->email,
            'plan' => $plan,
            'modules' => $plan->modules(),
            'industryLabel' => ($plan->industry ?? Industry::Other)->label(),
            'loginUrl' => route('login'),
            'launchDate' => WaitlistConfirmationMail::launchDate(),
            'signature' => (string) config('nonox.waitlist.signature'),
            'replyToAddress' => (string) config('nonox.waitlist.reply_to'),
            'fromAddress' => (string) config('mail.from.address'),
        ]);
    }

    public static function firstName(string $name): string
    {
        $first = trim(explode(' ', trim($name))[0]);

        return $first === '' ? $name : $first;
    }
}
