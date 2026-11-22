<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Signing\CompletionFiles;
use App\Domain\Signing\CompletionLinks;
use App\Domain\Signing\SigningPdfs;
use App\Models\Company;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
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
 * The copy after signing (ADR-046): the signed PDF, unchanged, and the
 * signing certificate with its SHA-256, to the signer and to the company, in the
 * recipient's language; above the mail limit a link with its own token instead of
 * the files (S13).
 * Carries ids only and reads the files inside the company when sent.
 */
class SignedDocumentMail extends Mailable implements ShouldBeEncrypted
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $companyId,
        public readonly bool $toSigner,
        public readonly string $language = 'sv',
        public readonly ?string $downloadToken = null,
    ) {
        $this->locale($language === 'en' ? 'en' : 'sv');
    }

    /** A link issued when the mail is sent because the files grew past the mail limit after it was queued (ADR-046 S11). */
    private ?string $lateToken = null;

    private bool $sizeChecked = false;

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
        $token = $this->token();

        return new Content(view: 'mail.signing.signed', with: [
            'request' => $this->request(),
            'companyName' => $company->legal_name ?: $company->name,
            'color' => Brand::readable(Brand::for($company)->color),
            'title' => SigningPdfs::title($this->request()),
            'toSigner' => $this->toSigner,
            'downloadUrl' => $token === null ? null : CompletionLinks::url($token),
            'expires' => $token === null ? null : CurrentCompany::run($this->companyId, fn (): ?CarbonImmutable => CompletionLinks::find($token)?->expires_at->setTimezone(config('app.timezone'))),
        ]);
    }

    /**
     * The finished version the signer was shown and the certificate (CompletionFiles); none when the files are
     * above the mail limit and the mail carries a link instead (ADR-046 S13).
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if ($this->token() !== null) {
            return [];
        }

        $request = $this->request();
        $files = CurrentCompany::run($this->companyId, fn (): array => CompletionFiles::forSigner($request));

        return array_map(fn (array $file): Attachment => Attachment::fromData(fn (): string => CurrentCompany::run($this->companyId, fn (): string => CompletionFiles::bytes($file)), $file['name'])->withMime('application/pdf'), $files);
    }

    /**
     * The download link: the one decided when the mail was queued, else one issued now when the files resolved at
     * sending are above the mail limit (they may have grown meanwhile, for example by long-term validation, S11).
     */
    private function token(): ?string
    {
        if ($this->downloadToken !== null) {
            return $this->downloadToken;
        }

        if (! $this->sizeChecked) {
            $this->sizeChecked = true;
            $email = (string) ($this->to[0]['address'] ?? '');
            $request = $this->request();

            $this->lateToken = $email === '' || $request->envelope_id === null ? null : CurrentCompany::run($this->companyId, function () use ($request, $email): ?string {
                $envelope = SigningEnvelope::query()->find($request->envelope_id);

                return $envelope instanceof SigningEnvelope
                    ? CompletionLinks::issueIf(CompletionFiles::tooLargeForMail(CompletionFiles::forSigner($request)), $envelope, $this->toSigner ? SigningDownloadLink::SIGNER : SigningDownloadLink::COMPANY, $email, $this->language, $request)
                    : null;
            });
        }

        return $this->lateToken;
    }

    private function request(): SigningRequest
    {
        return CurrentCompany::run($this->companyId, fn (): SigningRequest => SigningRequest::query()->with(['quote', 'agreement', 'events'])->findOrFail($this->requestId));
    }
}
