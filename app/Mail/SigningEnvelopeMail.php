<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Signing\CompletionFiles;
use App\Domain\Signing\CompletionLinks;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

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
class SigningEnvelopeMail extends Mailable implements ShouldBeEncrypted
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
        public readonly string $language = 'sv',
        public readonly ?string $downloadToken = null,
    ) {
        $this->locale($language === 'en' ? 'en' : 'sv');
    }

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
            'downloadUrl' => $this->downloadToken === null ? null : CompletionLinks::url($this->downloadToken),
            'expires' => $this->downloadToken === null ? null : CurrentCompany::run($this->companyId, fn (): ?CarbonImmutable => CompletionLinks::find($this->downloadToken)?->expires_at->setTimezone(config('app.timezone'))),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if (! in_array($this->kind, [self::COPY, self::COMPANY], true) || $this->downloadToken !== null) {
            return [];
        }

        $envelope = $this->signingEnvelope();
        $companyId = $this->companyId;
        // With fields (ADR-046 S6) the finished document is the version with every signer's values; copy recipients and the
        // other signers get the summary only, the full certificates go to the company (CompletionFiles).
        $files = CurrentCompany::run($companyId, fn (): array => CompletionFiles::forEnvelope($envelope, $this->kind));

        return array_map(fn (array $file): Attachment => Attachment::fromData(fn (): string => CurrentCompany::run($companyId, fn (): string => CompletionFiles::bytes($file)), $file['name'])->withMime('application/pdf'), $files);
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
