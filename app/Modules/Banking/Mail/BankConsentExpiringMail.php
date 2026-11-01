<?php

declare(strict_types=1);

namespace App\Modules\Banking\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A reminder that the consent to read one or more bank accounts runs out
 * soon. Renewing it is a new approval with BankID under Bank.
 */
class BankConsentExpiringMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{bank: string, date: string}>  $banks
     */
    public function __construct(public readonly array $banks, public readonly string $company) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Förnya bankkopplingen i NONOX'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'banking::mail.consent-expiring', with: ['url' => route('banking.index')]);
    }
}
