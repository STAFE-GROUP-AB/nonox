<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Signing\SigningPdfs;
use App\Models\Company;
use App\Models\SigningRequest;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A reminder to sign (ADR-046 amendment, gap 9): a fresh personal link to
 * a signer whose step is open, sent by signing:remind or by hand ("Påminn
 * nu"), in the company's name like the first mail. It names the document
 * and the last day, never another signer. Carries ids only.
 */
class SigningReminderMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $companyId,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        $company = Company::query()->findOrFail($this->companyId);
        $name = $company->legal_name ?: $company->name;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $company->email ? [$company->email] : [],
            subject: __('Påminnelse: :title från :company att signera', ['title' => $this->title(), 'company' => $name]),
        );
    }

    public function content(): Content
    {
        return CurrentCompany::run($this->companyId, function (): Content {
            $request = SigningRequest::query()->with(['quote', 'agreement'])->findOrFail($this->requestId);
            $company = Company::query()->findOrFail($this->companyId);

            return new Content(view: 'mail.signing.reminder', with: [
                'company' => $company,
                'companyName' => $company->legal_name ?: $company->name,
                'title' => SigningPdfs::title($request),
                'url' => $this->url,
                'color' => Brand::readable(Brand::for($company)->color),
                'expires' => $request->expires_at->setTimezone(config('app.timezone')),
            ]);
        });
    }

    private function title(): string
    {
        return CurrentCompany::run($this->companyId, fn (): string => SigningPdfs::title(SigningRequest::query()->with(['quote', 'agreement'])->findOrFail($this->requestId)));
    }
}
