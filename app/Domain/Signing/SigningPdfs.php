<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\Company;
use App\Models\SigningRequest;
use App\Support\Branding\Brand;
use Dompdf\Dompdf;
use Dompdf\Options;

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
        $request->loadMissing(['events']);
        $company = Company::query()->findOrFail($request->company_id);

        return self::render(view('signing.certificate', [
            'request' => $request,
            'company' => $company,
            'brand' => Brand::for($company),
            'title' => self::title($request),
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

    /** HTML to an A4 PDF with dompdf, no remote resources. */
    public static function render(string $html): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
