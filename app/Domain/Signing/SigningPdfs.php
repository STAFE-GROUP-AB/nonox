<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Domain\Signing\Pdf\PdfScripts;
use App\Domain\Signing\Seal\SealCertificateInfo;
use App\Domain\Signing\Verification\SignerCheck;
use App\Models\Company;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Support\Branding\Brand;
use Closure;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\App;

/**
 * The PDFs of signing (ADR-046, ADR-002): the document that is shown and
 * signed comes from the Signable itself; this renders the signing
 * certificate (Signeringsbevis) that records the evidence and the SHA-256
 * of the exact PDF that was signed. The signed PDF itself is never changed:
 * its bytes stay those that were shown, so anyone can recompute the hash.
 */
final class SigningPdfs
{
    /**
     * The document to sign, as bytes and a file name.
     *
     * @return array{bytes: string, name: string}
     */
    public static function document(Signable $document): array
    {
        return $document->signingDocument();
    }

    /** The signing certificate: what was signed (hash), by whom, how, when, from where, and the trail of events. */
    public static function certificate(SigningRequest $request): string
    {
        $request->loadMissing($request->verification === SignerCheck::Provider ? ['events', 'providerSignature'] : ['events']);
        $company = Company::query()->findOrFail($request->company_id);

        return self::render(view('signing.certificate', [
            'request' => $request,
            'company' => $company,
            'brand' => Brand::for($company),
            'title' => self::title($request),
        ])->render());
    }

    /**
     * The summary of a completed envelope with several signers (ADR-046
     * amendment, gap 1), for the copy recipients and the other signers: the
     * document, its SHA-256, and each signer's name, title, time and method.
     * Never the e-mail addresses, IP addresses, browsers or drawn signatures
     * (data minimisation, GDPR art. 5.1 c): those stay in each signer's full
     * certificate, which only the company and that signer get.
     */
    public static function envelopeSummary(SigningEnvelope $envelope): string
    {
        $company = Company::query()->findOrFail($envelope->company_id);
        $signers = SigningRequest::query()->where('envelope_id', $envelope->id)->where('status', SigningStatus::Signed->value)->orderBy('step')->orderBy('signed_at')->orderBy('id')
            ->get(['id', 'step', 'signer_name', 'signer_title', 'signed_at', 'signature_method', 'verification']);

        return self::render(view('signing.summary', [
            'envelope' => $envelope,
            'signers' => $signers,
            'company' => $company,
            'brand' => Brand::for($company),
            'title' => (string) $envelope->title,
            // With fields the copy is the filled-in version, whose SHA-256 is not the one above (S10 review).
            'withFields' => SigningDocumentVersion::query()->where('envelope_id', $envelope->id)->where('kind', SigningDocumentVersion::AFTER_SIGNER)->exists(),
        ])->render());
    }

    /**
     * The page appended to the finished file before it is sealed (ADR-046
     * amendment, gap 5, package S10): the summary of the signing (never an
     * address, IP address or browser, since the sealed file goes to every
     * recipient) and what the seal is. It names no seal level: the level is
     * known only after sealing, and the page must never state a higher one
     * than reached; the PDF reader's signature panel and the envelope page
     * show the level.
     */
    public static function sealAppendix(SigningEnvelope $envelope, SealCertificateInfo $seal, string $source): string
    {
        $company = Company::query()->findOrFail($envelope->company_id);
        $signers = SigningRequest::query()->where('envelope_id', $envelope->id)->where('status', SigningStatus::Signed->value)->orderBy('step')->orderBy('signed_at')->orderBy('id')
            ->get(['id', 'step', 'signer_name', 'signer_title', 'signed_at', 'signature_method', 'verification']);

        return self::render(view('signing.summary', [
            'envelope' => $envelope,
            'signers' => $signers,
            'company' => $company,
            'brand' => Brand::for($company),
            'title' => (string) $envelope->title,
            'seal' => ['name' => $seal->commonName, 'issuer' => $seal->issuer, 'fingerprint' => $seal->fingerprintSha256, 'selfSigned' => $seal->selfSigned, 'source' => $source],
        ])->render());
    }

    /** "Offert 12" or the agreement's title. */
    public static function title(SigningRequest $request): string
    {
        $document = $request->document();

        if ($document instanceof Signable) {
            return $document->signingTitle();
        }

        return $request->isQuote() ? __('Offert') : __('Avtal');
    }

    /**
     * The confirmation the signer ticks, exactly as shown: for a company,
     * also that they may accept on its behalf; a private person keeps the
     * short form. Stored with the signature and quoted in the certificate.
     */
    public static function acceptance(SigningRequest $request): string
    {
        $document = $request->document();

        if ($document instanceof Signable) {
            return $document->signingAcceptance();
        }

        return $request->isQuote() ? __('Jag har läst och godkänner offerten.') : __('Jag har läst och godkänner avtalet.');
    }

    /**
     * A MAC over the confirmation shown on the page, so the text stored with
     * the signature is exactly the one displayed, even if a name changed in
     * between; the form carries both and the server checks them.
     */
    public static function acceptanceMac(SigningRequest $request, string $text): string
    {
        return hash_hmac('sha256', "crm-signing-acceptance|{$request->id}|{$text}", (string) config('app.key'));
    }

    /**
     * The confirmation and its MAC as the page's form posts them.
     *
     * @return array{acceptance_text: string, acceptance_mac: string}
     */
    public static function acceptanceFields(SigningRequest $request): array
    {
        $text = self::acceptance($request);

        return ['acceptance_text' => $text, 'acceptance_mac' => self::acceptanceMac($request, $text)];
    }

    public static function certificateFilename(SigningRequest $request): string
    {
        return "Signeringsbevis-{$request->id}.pdf";
    }

    /**
     * HTML to an A4 PDF with dompdf, no remote resources. Chinese, Japanese
     * and Korean text gets Noto Sans CJK where this installation has it, and
     * otherwise a placeholder and a notice (PdfScripts, S20).
     */
    public static function render(string $html): string
    {
        $font = PdfScripts::cjkFont();
        $prepared = PdfScripts::prepare($html, $font !== null);

        if ($prepared['degraded']) {
            $notice = '<div class="box"><span class="muted">'.self::bilingualBlock('Namnet eller texten innehåller kinesiska, japanska eller koreanska tecken, men typsnittet för dem finns inte installerat här. De visas som […]. Det exakta innehållet är det som lagrats och som kontrollsumman och händelsekedjan gäller; signeringsbeviset visar bara inte tecknen.').'</span></div>';
            $prepared['html'] = str_contains($prepared['html'], '</body>') ? str_replace('</body>', $notice.'</body>', $prepared['html']) : $prepared['html'].$notice;
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = $prepared['cjk'] && $font !== null ? PdfScripts::dompdfWith($options, $font) : new Dompdf($options);
        $pdf->loadHtml($prepared['html']);
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * The text in Swedish and English, "Namn / Name" (decision 19: one
     * bilingual certificate for every signer). Identical texts, such as a
     * name or a number, are shown once.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function bilingual(string $key, array $replace = []): string
    {
        return self::bilingualOf(fn (): string => __($key, $replace));
    }

    /** The text as two paragraphs, Swedish then English, escaped, for a longer notice. */
    public static function bilingualBlock(string $key): string
    {
        $sv = self::inLocale('sv', fn (): string => __($key));
        $en = self::inLocale('en', fn (): string => __($key));

        return $sv === $en ? '<p>'.e($sv).'</p>' : '<p>'.e($sv).'</p><p lang="en">'.e($en).'</p>';
    }

    /** What the closure returns in Swedish and in English, as two paragraphs, escaped: bilingualBlock for a text with replacements. */
    public static function bilingualBlockOf(Closure $text): string
    {
        $sv = self::inLocale('sv', $text);
        $en = self::inLocale('en', $text);

        return $sv === $en ? '<p>'.e($sv).'</p>' : '<p>'.e($sv).'</p><p lang="en">'.e($en).'</p>';
    }

    /** What the closure returns in Swedish and in English, joined. */
    public static function bilingualOf(Closure $text): string
    {
        $sv = self::inLocale('sv', $text);
        $en = self::inLocale('en', $text);

        return $sv === $en ? $sv : "{$sv} / {$en}";
    }

    private static function inLocale(string $locale, Closure $text): string
    {
        $before = App::getLocale();
        App::setLocale($locale);

        try {
            return $text();
        } finally {
            App::setLocale($before);
        }
    }
}
