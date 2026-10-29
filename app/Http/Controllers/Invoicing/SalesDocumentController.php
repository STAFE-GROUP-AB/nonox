<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invoicing;

use App\Domain\Invoicing\SalesDocumentPdf;
use App\Models\SalesDocument;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Quote and order pages. Route model binding goes through the company scope.
 */
class SalesDocumentController
{
    public function show(SalesDocument $document): View
    {
        Gate::authorize('view', $document);

        return view('invoicing.sales-documents.show', ['document' => $document, 'kind' => $document->kind]);
    }

    public function pdf(SalesDocument $document): Response
    {
        Gate::authorize('view', $document);

        return response(SalesDocumentPdf::render($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.SalesDocumentPdf::filename($document).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
