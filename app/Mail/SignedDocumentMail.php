<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Signing\SigningPdfs;
use App\Models\Company;
use App\Models\SigningRequest;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/**
 * The copy after signing (ADR-046): the signed PDF, unchanged, and the
 * signing certificate with its SHA-256, to the signer and to the company.
 * Carries ids only and reads the files inside the company when sent.
 */
class SignedDocumentMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $companyId,
        public readonly bool $toSigner,
    ) {}

    public function envelope(): Envelope
    {
        $company = Company::query()->findOrFail($this->companyId);
        $name = $company->legal_name ?: $company->name;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $this->toSigner && $company->email ? [$company->email] : [],
            subject: __('Signerat: :title', ['title' => SigningPdfs::title($this->request())]),
        );
    }

    public function content(): Content
    {
        $company = Company::query()->findOrFail($this->companyId);

        return new Content(view: 'mail.signing.signed', with: [
            'request' => $this->request(),
            'companyName' => $company->legal_name ?: $company->name,
            'title' => SigningPdfs::title($this->request()),
            'toSigner' => $this->toSigner,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $request = $this->request();
        $disk = Storage::disk(SigningRequest::DISK);

        $attachments = [Attachment::fromData(fn (): string => (string) $disk->get($request->document_path), $request->document_name)->withMime('application/pdf')];

        if ($request->signed_document_path !== null) {
            $certificate = $request->signed_document_path;
            $attachments[] = Attachment::fromData(fn (): string => (string) $disk->get($certificate), SigningPdfs::certificateFilename($request))->withMime('application/pdf');
        }

        return $attachments;
    }

    private function request(): SigningRequest
    {
        return CurrentCompany::run($this->companyId, fn (): SigningRequest => SigningRequest::query()->with(['quote', 'agreement', 'events'])->findOrFail($this->requestId));
    }
}
