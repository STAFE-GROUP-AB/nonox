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
 * Tells an open signer that the last day to sign was changed (ADR-046
 * amendment, gap 9). No link: the signer's earlier mail still holds one, and
 * a new link comes with the next reminder. Carries ids only.
 */
class SigningDeadlineMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $companyId,
    ) {}

    public function envelope(): Envelope
    {
        $company = Company::query()->findOrFail($this->companyId);
        $name = $company->legal_name ?: $company->name;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $company->email ? [$company->email] : [],
            subject: __('Ny sista dag att signera: :title från :company', ['title' => $this->title(), 'company' => $name]),
        );
    }

    public function content(): Content
    {
        return CurrentCompany::run($this->companyId, function (): Content {
            $request = SigningRequest::query()->findOrFail($this->requestId);
            $company = Company::query()->findOrFail($this->companyId);

            return new Content(view: 'mail.signing.deadline', with: [
                'company' => $company,
                'companyName' => $company->legal_name ?: $company->name,
                'title' => SigningPdfs::title($request),
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
