<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pricing;

use App\Domain\Pricing\PriceAgreementPdf;
use App\Models\PriceAgreement;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The price agreement as a PDF (ADR-082 P5), to read before sending it or to
 * keep. Bound within the company, so another company's agreement is a 404;
 * seeing it needs prices:view. The signed copy itself is kept by the
 * signing engine with its certificate.
 */
final class PriceAgreementPdfController
{
    public function __invoke(PriceAgreement $priceAgreement): Response
    {
        Gate::authorize('view', $priceAgreement);

        return response(PriceAgreementPdf::render($priceAgreement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.PriceAgreementPdf::filename($priceAgreement).'"',
        ]);
    }
}
