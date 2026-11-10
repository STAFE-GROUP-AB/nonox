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

/**
 * Tells someone on the waiting list that they may log in now, in a personal
 * mail built from what they answered on the waiting list (ADR-038 addendum):
 * their name and company, where they come from, whether they have employees
 * and what the company does. Sent from the instance's normal address; replies
 * go to the person who signs it.
 *
 * The mail keeps plain values, not the entry, so it still works if the entry
 * is deleted before the queue picks it up. Its properties are named so that
 * they do not clash with the view's variables: a public property overrides the
 * view data of the same name, and the view gets the escaped values.
 */
class WaitlistApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public readonly string $applicantName;

    public readonly string $forCompany;

    public readonly string $forEmail;

    public readonly OnboardingPlan $plan;

    public function __construct(WaitlistEntry $entry)
    {
        $this->applicantName = $entry->name;
        $this->forCompany = $entry->company_name;
        $this->forEmail = $entry->email;
        $this->plan = OnboardingPlan::forEntry($entry);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Du är godkänd – logga in i NONOX'),
            replyTo: [OnboardingPlan::replyTo()],
        );
    }

    public function content(): Content
    {
        $escape = OnboardingPlan::escapeMarkdown(...);

        return new Content(markdown: 'emails.waitlist-approved', with: [
            'firstName' => $escape(self::firstName($this->applicantName)),
            'companyName' => $escape($this->forCompany),
            'email' => $escape($this->forEmail),
            'plan' => $this->plan,
            'moduleLines' => $this->moduleLines(),
            'industryPhrase' => $this->plan->phrase(),
            'loginUrl' => route('login'),
            'launchDate' => WaitlistConfirmationMail::launchDate(),
            'signature' => $escape(OnboardingPlan::signature()),
            'replyToAddress' => OnboardingPlan::replyTo(),
            'fromAddress' => (string) config('mail.from.address'),
        ]);
    }

    /**
     * One Markdown line per suggested module: its name linked to the manual,
     * and any further article after it. The names come from the module
     * registry, never from the applicant.
     *
     * @return list<string>
     */
    private function moduleLines(): array
    {
        return array_map(function (array $module): string {
            $links = ["[{$module['name']}]({$module['manual']})"];

            foreach ($module['extra'] as $extra) {
                $links[] = "[{$extra['name']}]({$extra['url']})";
            }

            return implode(', ', $links);
        }, $this->plan->modules());
    }

    public static function firstName(string $name): string
    {
        $first = trim(explode(' ', trim($name))[0]);

        return $first === '' ? $name : $first;
    }
}
