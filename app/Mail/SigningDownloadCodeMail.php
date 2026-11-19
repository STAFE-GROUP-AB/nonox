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
 * The six-digit code a recipient types before the files of a confidential
 * signing are downloaded (ADR-046 S13 amendment, 2026-11-19). Sent at once,
 * never queued, in the company's name and in the language of the link.
 */
class SigningDownloadCodeMail extends Mailable
{
    public readonly string $companyName;

    public readonly ?string $replyAddress;

    public readonly string $color;

    public function __construct(Company $company, public readonly string $title, public readonly string $code, public readonly int $minutes, string $language)
    {
        $this->companyName = $company->legal_name ?: $company->name;
        $this->replyAddress = $company->email ?: null;
        $this->color = Brand::readable(Brand::for($company)->color);
        $this->locale($language === 'en' ? 'en' : 'sv');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->companyName),
            replyTo: $this->replyAddress === null ? [] : [$this->replyAddress],
            subject: __('Din kod för att ladda ner :title', ['title' => $this->title]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.signing.download-code');
    }
}
