<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Domain\Invoicing\SalesDocumentPdf;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\SigningRequest;
use App\Support\Branding\Brand;
use App\Support\Money\Money;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The PDFs of signing (ADR-046, ADR-002): the document that is shown and
 * signed (a quote's own PDF, an agreement's uploaded PDF, or one generated
 * from the agreement's fields and text), and the signing certificate
 * (Signeringsbevis) that records the evidence and the SHA-256 of the exact
 * PDF that was signed. The signed PDF itself is never changed: its bytes
 * stay those that were shown, so anyone can recompute the hash.
 */
final class SigningPdfs
{
    /**
     * The document to sign, as bytes and a file name.
     *
     * @return array{bytes: string, name: string}
     */
    public static function document(SalesDocument|Agreement $document): array
    {
        if ($document instanceof SalesDocument) {
            return ['bytes' => SalesDocumentPdf::render($document), 'name' => SalesDocumentPdf::filename($document)];
        }

        if ($document->document_path !== null) {
            $bytes = Storage::disk(SigningRequest::DISK)->get($document->document_path);

            if (! is_string($bytes) || $bytes === '') {
                throw new RuntimeException('The agreement\'s document is missing.');
            }

            return ['bytes' => $bytes, 'name' => self::agreementFilename($document)];
        }

        return ['bytes' => self::agreement($document), 'name' => self::agreementFilename($document)];
    }

    /** An agreement without an uploaded PDF: its parties, term, notice, renewal, value and text. */
    public static function agreement(Agreement $agreement): string
    {
        $agreement->loadMissing(['account']);
        $company = Company::query()->findOrFail($agreement->company_id);

        return self::render(view('crm::pdf.agreement', [
            'agreement' => $agreement,
            'company' => $company,
            'brand' => Brand::for($company),
            'value' => Money::ofOre($agreement->value_ore),
        ])->render());
    }

    /** The signing certificate: what was signed (hash), by whom, how, when, from where, and the trail of events. */
    public static function certificate(SigningRequest $request): string
    {
        $request->loadMissing(['events', 'quote', 'agreement']);
        $company = Company::query()->findOrFail($request->company_id);

        return self::render(view('crm::pdf.signing-certificate', [
            'request' => $request,
            'company' => $company,
            'brand' => Brand::for($company),
            'title' => self::title($request),
        ])->render());
    }

    /** "Offert 12" or the agreement's title. */
    public static function title(SigningRequest $request): string
    {
        if ($request->isQuote()) {
            return $request->quote instanceof SalesDocument ? $request->quote->title() : __('Offert');
        }

        return $request->agreement instanceof Agreement ? $request->agreement->title : __('Avtal');
    }

    /**
     * The confirmation the signer ticks, exactly as shown: for a company,
     * also that they may accept on its behalf; a private person keeps the
     * short form. Stored with the signature and quoted in the certificate.
     */
    public static function acceptance(SigningRequest $request): string
    {
        $request->loadMissing(['quote.customer', 'agreement']);

        if ($request->isQuote()) {
            $customer = $request->quote?->customer;
            $account = $customer === null ? null : Account::query()->where('customer_id', $customer->id)->first();
            // The CRM account's kind, else the customer register's: a legal person has an organisation number and no personal number.
            $legalPerson = $account instanceof Account
                ? $account->kind === AccountKind::Company
                : $customer !== null && $customer->org_number !== null && $customer->personal_number === null;

            return $legalPerson
                ? __('Jag har läst och godkänner offerten och har rätt att godkänna den för :customer.', ['customer' => $account->name ?? $customer?->name])
                : __('Jag har läst och godkänner offerten.');
        }

        $account = Account::query()->find($request->agreement?->account_id);

        return $account instanceof Account && $account->kind === AccountKind::Company
            ? __('Jag har läst och godkänner avtalet och har rätt att ingå det för :account.', ['account' => $account->name])
            : __('Jag har läst och godkänner avtalet.');
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

    private static function agreementFilename(Agreement $agreement): string
    {
        return "Avtal-{$agreement->id}.pdf";
    }

    private static function render(string $html): string
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
