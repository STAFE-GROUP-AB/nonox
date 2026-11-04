<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A hand-written mail from the operator, in the same layout as every other
 * NONOX mail. The sender is the instance's own MAIL_FROM_ADDRESS; replies go
 * to the address given per mail, so the recipient sees NONOX as the sender
 * but answers a person.
 *
 * The body is Markdown written by the operator (never user input) and is
 * inserted raw so headings, lists and links render. Blade components cannot
 * be used inside it, hence the button as separate parameters.
 */
class BrandedMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $heading,
        public readonly string $bodyMarkdown,
        public readonly string $subjectLine,
        public readonly string $replyToAddress,
        public readonly string $signature = 'Andreas Kviby',
        public readonly string $buttonText = '',
        public readonly string $buttonUrl = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine, replyTo: [$this->replyToAddress]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.branded', with: [
            'fromAddress' => config('mail.from.address'),
        ]);
    }
}
