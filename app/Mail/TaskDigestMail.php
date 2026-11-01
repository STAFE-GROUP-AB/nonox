<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The morning summary of a user's open tasks across their companies, for
 * those who asked for it (ADR-028). Titles, companies and deadlines; no
 * descriptions or comments.
 */
class TaskDigestMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{company: string, title: string, due: ?string, overdue: bool, status: string}>  $tasks
     */
    public function __construct(public readonly string $userName, public readonly array $tasks) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Dina uppgifter i dag (:count)', ['count' => count($this->tasks)]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.task-digest', with: ['url' => route('tasks.mine')]);
    }
}
