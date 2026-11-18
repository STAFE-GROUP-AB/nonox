<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Company;
use App\Support\Branding\Brand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The six-digit code a signer types before the document is shown (ADR-046
 * amendment, gap 3, package S3). Sent at once, never queued: the signer is
 * waiting, and the code must not sit in a queue table. In the company's
 * name, like the link itself.
 */
class SigningCodeMail extends Mailable
{
    public readonly string $companyName;

    public readonly ?string $replyAddress;

    public readonly string $color;

    public function __construct(Company $company, public readonly string $title, public readonly string $code, public readonly int $minutes)
    {
        $this->companyName = $company->legal_name ?: $company->name;
        $this->replyAddress = $company->email ?: null;
        $this->color = Brand::readable(Brand::for($company)->color);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->companyName),
            replyTo: $this->replyAddress === null ? [] : [$this->replyAddress],
            subject: __('Din kod för att öppna :title', ['title' => $this->title]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.signing.code');
    }
}
