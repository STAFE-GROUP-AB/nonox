<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Mail;

use App\Models\Company;
use App\Modules\WorkOrders\Models\ChangeOrder;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The code the customer types on the field worker's phone before signing an
 * ÄTA on site (ADR-074 O4). Sent at once, never queued: the customer is
 * standing there.
 */
class OnSiteCodeMail extends Mailable
{
    public readonly string $companyName;

    public readonly string $title;

    public function __construct(Company $company, ChangeOrder $changeOrder, public readonly string $code)
    {
        $this->companyName = $company->legal_name ?: $company->name;
        $this->title = $changeOrder->signingTitle();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->companyName),
            subject: __('Din kod för att signera :title', ['title' => $this->title]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'work-orders::mail.on-site-code');
    }
}
