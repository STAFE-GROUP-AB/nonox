<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Modules\Crm\Actions\SignAtLink;
use App\Modules\Crm\Domain\QuoteFingerprint;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\SigningRequest;
use App\Modules\Crm\Support\SigningPdfs;
use App\Modules\Crm\Support\SigningUnavailable;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public signing page, /signera/{token} (ADR-046). No account: the
 * token is the only key and is looked up by its hash. Every answer is
 * private (no-store), never indexed (X-Robots-Tag, robots meta), sends no
 * Referer (the token is in the address), cannot be framed, and the page
 * allows scripts from NONOX only (no inline script). The form posts with
 * Laravel's CSRF token. A link that cannot be used shows why, in Swedish,
 * and never anything about the document.
 */
final class SigningPageController
{
    public function __construct(private readonly SignAtLink $links) {}

    public function show(string $token): Response
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        $reason = $this->links->unavailable($request);

        if ($reason === SigningUnavailable::EXPIRED) {
            $this->links->noteExpired($request);
        }

        if ($reason === null && $this->documentChanged($request)) {
            $this->links->revokeStale($request);
            $reason = SigningUnavailable::CHANGED;
        }

        if ($reason !== null) {
            return $this->unavailable($reason, $reason === SigningUnavailable::GONE ? 404 : 410, $request);
        }

        $this->links->open($request);

        return $this->page($request, $token);
    }

    /** The exact PDF that is signed, inline. */
    public function document(string $token): Response
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest || ! in_array($this->links->unavailable($request), [null, SigningUnavailable::SIGNED], true)) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        $bytes = Storage::disk(SigningRequest::DISK)->get($request->document_path);
        abort_unless(is_string($bytes), 404);

        return $this->private(response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$request->document_name.'"',
        ]));
    }

    public function sign(Request $http, string $token): Response|RedirectResponse
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        try {
            $this->links->sign($request, $http->only(['name', 'title', 'accept', 'method', 'signature', 'typed_signature', 'document_sha256', 'acceptance_text', 'acceptance_mac']), $http->ip(), $http->userAgent());
        } catch (SigningUnavailable $unavailable) {
            if ($unavailable->reason === SigningUnavailable::CHANGED) {
                $this->links->revokeStale($request);
            }

            return $this->unavailable($unavailable->reason, 410, $request);
        } catch (ValidationException $exception) {
            // The drawn signature is not sent back into the form; the signer draws it again.
            return redirect()->route('crm.signing.show', ['token' => $token])
                ->withErrors($exception->errors())
                ->withInput($http->except(['signature', '_token']));
        }

        return $this->unavailable(SigningUnavailable::SIGNED, 200, $request->refresh(), signedNow: true);
    }

    public function decline(Request $http, string $token): Response
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        try {
            // Declining keeps no IP address or browser: only the time and the reason (ADR-046, data minimisation).
            $this->links->decline($request, $http->filled('reason') ? (string) $http->input('reason') : null);
        } catch (SigningUnavailable $unavailable) {
            return $this->unavailable($unavailable->reason, 410, $request);
        }

        return $this->unavailable(SigningUnavailable::DECLINED, 200, $request, declinedNow: true);
    }

    private function documentChanged(SigningRequest $request): bool
    {
        return CurrentCompany::run($request->company_id, function () use ($request): bool {
            if ($request->isQuote()) {
                $quote = SalesDocument::query()->find($request->sales_document_id);

                return ! $quote instanceof SalesDocument || ! $quote->isOpen() || ! hash_equals((string) $request->content_hash, QuoteFingerprint::for($quote));
            }

            $agreement = Agreement::query()->find($request->agreement_id);

            return ! $agreement instanceof Agreement || $agreement->signed_at !== null || ! hash_equals((string) $request->content_hash, QuoteFingerprint::forAgreement($agreement));
        });
    }

    private function page(SigningRequest $request, string $token): Response
    {
        return CurrentCompany::run($request->company_id, function () use ($request, $token): Response {
            $company = Company::query()->findOrFail($request->company_id);
            $request->load(['quote.rows', 'quote.customer', 'agreement.account']);
            $quote = $request->quote;
            $acceptance = SigningPdfs::acceptance($request);
            $contact = implode(', ', array_filter([$company->org_number ? (string) __('org.nr :number', ['number' => $company->org_number]) : null, $company->email ?: null]));

            return $this->private(response()->view('crm::signing.page', [
                'request' => $request,
                'token' => $token,
                'company' => $company,
                'companyName' => $company->legal_name ?: $company->name,
                'brand' => Brand::for($company),
                'color' => Brand::readable(Brand::for($company)->color),
                'title' => SigningPdfs::title($request),
                'acceptance' => $acceptance,
                'acceptanceMac' => SigningPdfs::acceptanceMac($request, $acceptance),
                'controller' => ($company->legal_name ?: $company->name).($contact === '' ? '' : " ({$contact})"),
                'quote' => $quote,
                'agreement' => $request->agreement,
                'vat' => $quote instanceof SalesDocument ? InvoiceCalculator::totals($quote->rows->map(fn ($row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all()) : null,
            ]), page: true);
        });
    }

    private function unavailable(string $reason, int $status, ?SigningRequest $request = null, bool $signedNow = false, bool $declinedNow = false): Response
    {
        $company = $request === null ? null : Company::query()->find($request->company_id);

        return $this->private(response()->view('crm::signing.closed', [
            'reason' => $reason,
            'message' => SigningUnavailable::message($reason),
            'signedNow' => $signedNow,
            'declinedNow' => $declinedNow,
            // The sender's name only for a link that once was valid; an unknown token reveals nothing.
            'companyName' => $company instanceof Company ? ($company->legal_name ?: $company->name) : null,
        ], $status), page: true);
    }

    private function private(Response $response, bool $page = false): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if ($page && ! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "frame-src 'self'",
                "frame-ancestors 'none'",
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }
}
