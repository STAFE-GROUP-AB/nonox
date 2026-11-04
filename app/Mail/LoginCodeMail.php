<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Branding\PresentedBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Queued, so the sign-in form answers equally fast whether or not a mail goes
 * out: the response time does not tell whether an address has an account
 * (security review 2026-11-04). Encrypted on the queue, since it carries the
 * code in plain text.
 */
class LoginCodeMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly ?PresentedBrand $mailBrand = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // The code is not in the subject, where it would show on a locked screen.
            subject: __('Din inloggningskod'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.login-code',
            with: [
                'code' => $this->code,
                'minutes' => (int) config('auth.login_codes.ttl_minutes'),
            ],
        );
    }
}
