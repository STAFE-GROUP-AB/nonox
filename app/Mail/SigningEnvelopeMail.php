<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/**
 * The mails of an envelope with several signers (ADR-046 amendment, gap 1),
 * besides each signer's own link and copy:
 *
 * - NOTICE: to a copy recipient when the envelope is sent, if the sender
 *   ticked it. No link and no document.
 * - COPY: when every signer has signed, to each copy recipient and to every
 *   signer: the document as sent and a summary of the signing (names,
 *   titles, times, methods and the SHA-256; SigningPdfs::envelopeSummary).
 *   Never another signer's e-mail address, IP address, browser or drawn
 *   signature (GDPR art. 5.1 c).
 * - COMPANY: when every signer has signed, to the company: the document and
 *   every signer's full signing certificate.
 * - DECLINED: to a signer who had already signed, when another declined.
 *   The reason is not in it; it stays with the sender.
 *
 * Carries ids only and reads the envelope inside the company when sent.
 */
class SigningEnvelopeMail extends Mailable
{
    use Queueable;

    public const string NOTICE = 'NOTICE';

    public const string COPY = 'COPY';

    public const string COMPANY = 'COMPANY';

    public const string DECLINED = 'DECLINED';

    public function __construct(
        public readonly string $envelopeId,
        public readonly int $companyId,
        public readonly string $kind,
        public readonly bool $toSigner = false,
    ) {}

    public function envelope(): Envelope
    {
        $company = Company::query()->findOrFail($this->companyId);
        $name = $company->legal_name ?: $company->name;
        $title = (string) $this->signingEnvelope()->title;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $company->email ? [$company->email] : [],
            subject: match ($this->kind) {
                self::NOTICE => __('Kopia på väg: :title från :company', ['title' => $title, 'company' => $name]),
                self::DECLINED => __('Avböjt: :title', ['title' => $title]),
                default => __('Signerat av alla: :title', ['title' => $title]),
            },
        );
    }

    public function content(): Content
    {
        $company = Company::query()->findOrFail($this->companyId);
        $envelope = $this->signingEnvelope();

        return new Content(view: 'mail.signing.envelope', with: [
            'kind' => $this->kind,
            'envelope' => $envelope,
            'title' => (string) $envelope->title,
            'companyName' => $company->legal_name ?: $company->name,
            'color' => Brand::readable(Brand::for($company)->color),
            'signers' => $envelope->signers->count(),
            'toSigner' => $this->toSigner,
            'controller' => $this->controller($company),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if (! in_array($this->kind, [self::COPY, self::COMPANY], true)) {
            return [];
        }

        $envelope = $this->signingEnvelope();
        $disk = Storage::disk(SigningRequest::DISK);
        // With fields (ADR-046 S6) the finished document is the version with every signer's values.
        $document = CurrentCompany::run($this->companyId, fn (): string => SigningFields::finalVersion($envelope));
        $attachments = [Attachment::fromData(fn (): string => (string) $disk->get($document), $envelope->document_name)->withMime('application/pdf')];

        // Copy recipients and the other signers get the summary only; the full certificates go to the company.
        if ($this->kind === self::COPY) {
            $companyId = $this->companyId;
            $attachments[] = Attachment::fromData(fn (): string => CurrentCompany::run($companyId, fn (): string => SigningPdfs::envelopeSummary($envelope)), __('Sammanstallning-av-signeringen.pdf'))->withMime('application/pdf');

            return $attachments;
        }

        foreach ($envelope->signers as $position => $signer) {
            if ($signer->status !== SigningStatus::Signed || $signer->signed_document_path === null) {
                continue;
            }

            $certificate = $signer->signed_document_path;
            $attachments[] = Attachment::fromData(fn (): string => (string) $disk->get($certificate), __('Signeringsbevis-:number.pdf', ['number' => $position + 1]))->withMime('application/pdf');
        }

        return $attachments;
    }

    /** The controller as the signing page names it: the company, with its organisation number and e-mail when it has them. */
    private function controller(Company $company): string
    {
        $contact = implode(', ', array_filter([$company->org_number ? (string) __('org.nr :number', ['number' => $company->org_number]) : null, $company->email ?: null]));

        return ($company->legal_name ?: $company->name).($contact === '' ? '' : " ({$contact})");
    }

    private function signingEnvelope(): SigningEnvelope
    {
        return CurrentCompany::run($this->companyId, fn (): SigningEnvelope => SigningEnvelope::query()->with('signers')->findOrFail($this->envelopeId));
    }
}
