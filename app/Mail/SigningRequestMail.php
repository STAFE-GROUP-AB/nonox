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
 * The e-mail with the link to sign a quote or an agreement (ADR-046). Sent
 * in the company's name (its name as sender name, replies to its own
 * address), with its colour on the button. Carries ids only, so the queued
 * job holds no document; it reads them inside the company when sent.
 */
class SigningRequestMail extends Mailable
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
            subject: __(':title från :company att signera', ['title' => $this->title(), 'company' => $name]),
        );
    }

    public function content(): Content
    {
        return CurrentCompany::run($this->companyId, function (): Content {
            $request = SigningRequest::query()->with(['quote', 'agreement'])->findOrFail($this->requestId);
            $company = Company::query()->findOrFail($this->companyId);

            return new Content(view: 'mail.signing.request', with: [
                'request' => $request,
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
