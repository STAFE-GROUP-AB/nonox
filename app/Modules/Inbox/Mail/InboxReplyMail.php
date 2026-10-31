<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An answer from the company to someone who sent something to its inbox.
 * Replies go to the person who wrote it, not to the inbox.
 */
class InboxReplyMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $originalSubject,
        public readonly string $text,
        public readonly string $replyToEmail,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->originalSubject === '' ? __('Angående det du skickade till :company', ['company' => $this->companyName]) : 'Sv: '.$this->originalSubject;

        return new Envelope(subject: mb_substr($subject, 0, 200), replyTo: [new Address($this->replyToEmail)]);
    }

    public function content(): Content
    {
        return new Content(text: 'inbox::mail.reply');
    }
}
