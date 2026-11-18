<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Actions\Signing\SignAtLink;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningEnvelopes;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningUnavailable;
use App\Domain\Signing\Verification\CheckRefused;
use App\Domain\Signing\Verification\SignerCheck;
use App\Domain\Signing\Verification\SignerChecks;
use App\Domain\Signing\Verification\SignerPass;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningRequest;
use App\Models\SigningVerificationCode;
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
 *
 * A signer the sender chose to check (ADR-046 amendment, gap 3, S3) sees a
 * check page first: an e-mail code they ask for, or the access code the
 * sender gave them. Until the check has passed in this browser
 * (SignerPass), the document, signing and declining are refused.
 */
final class SigningPageController
{
    public function __construct(
        private readonly SignAtLink $links,
        private readonly SignerChecks $checks,
    ) {}

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

        // With a check, the link counts as opened only once the check has passed: the document is shown then, not before.
        if (! SignerPass::has(session()->driver(), $request, $token)) {
            return $this->check($request, $token);
        }

        $this->links->open($request);

        return $this->page($request, $token);
    }

    /** "Skicka kod": a code to the signer's own address (never on GET: a mail program that fetches the link sends nothing). */
    public function sendCode(string $token): Response|RedirectResponse
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        if (($reason = $this->links->unavailable($request)) !== null) {
            return $this->unavailable($reason, 410, $request);
        }

        try {
            $sent = $this->checks->sendCode($request);
        } catch (SigningUnavailable $unavailable) {
            return $this->unavailable($unavailable->reason, 410, $request);
        } catch (CheckRefused $refused) {
            return redirect()->route('signing.show', ['token' => $token])->withErrors(['code' => $refused->text()]);
        }

        return redirect()->route('signing.show', ['token' => $token])->with('signing-code', $sent === SignerChecks::REUSED
            ? __('En kod skickades för mindre än en minut sedan. Titta i din e-post, även i skräpposten.')
            : __('Koden är skickad till :email. Den gäller i :minutes minuter.', ['email' => self::masked($request->email), 'minutes' => SignerChecks::CODE_MINUTES]));
    }

    /** The signer types the code; a passed check is kept in this browser's session for thirty minutes. */
    public function verify(Request $http, string $token): Response|RedirectResponse
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        if (($reason = $this->links->unavailable($request)) !== null) {
            return $this->unavailable($reason, 410, $request);
        }

        try {
            $this->checks->verify($request, $http->input('code'));
        } catch (SigningUnavailable $unavailable) {
            return $this->unavailable($unavailable->reason, 410, $request);
        } catch (CheckRefused $refused) {
            return redirect()->route('signing.show', ['token' => $token])->withErrors(['code' => $refused->text()])->with('signing-code-sent', $refused->reason === CheckRefused::WRONG);
        } catch (ValidationException $exception) {
            return redirect()->route('signing.show', ['token' => $token])->withErrors($exception->errors())->with('signing-code-sent', true);
        }

        $http->session()->regenerate();
        SignerPass::grant($http->session(), $request->refresh(), $token);

        return redirect()->route('signing.show', ['token' => $token]);
    }

    /** The exact PDF that is signed, inline. */
    public function document(string $token): Response
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest || ! in_array($this->links->unavailable($request), [null, SigningUnavailable::SIGNED], true)) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        // A checked signer's PDF only after the check in this browser, also once signed: a forwarded link shows nothing.
        if (! SignerPass::has(session()->driver(), $request, $token)) {
            return redirect()->route('signing.show', ['token' => $token]);
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

        if (! SignerPass::has($http->session(), $request, $token)) {
            return $this->checkExpired($request, $token);
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
            return redirect()->route('signing.show', ['token' => $token])
                ->withErrors($exception->errors())
                ->withInput($http->except(['signature', '_token']));
        }

        return $this->unavailable(SigningUnavailable::SIGNED, 200, $request->refresh(), signedNow: true);
    }

    public function decline(Request $http, string $token): Response|RedirectResponse
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        if (! SignerPass::has($http->session(), $request, $token)) {
            return $this->checkExpired($request, $token);
        }

        try {
            // Declining keeps no IP address or browser: only the time and the reason (ADR-046, data minimisation).
            $this->links->decline($request, $http->filled('reason') ? (string) $http->input('reason') : null);
        } catch (SigningUnavailable $unavailable) {
            return $this->unavailable($unavailable->reason, 410, $request);
        } catch (ValidationException $exception) {
            return redirect()->route('signing.show', ['token' => $token])->withErrors($exception->errors());
        }

        return $this->unavailable(SigningUnavailable::DECLINED, 200, $request, declinedNow: true);
    }

    /** Signing or declining without a passed check: back to the check (or the page saying why the link is closed). */
    private function checkExpired(SigningRequest $request, string $token): Response|RedirectResponse
    {
        if (($reason = $this->links->unavailable($request)) !== null) {
            return $this->unavailable($reason, 410, $request);
        }

        return redirect()->route('signing.show', ['token' => $token])->withErrors(['code' => __('Kontrollen behöver göras om innan du kan fortsätta.')]);
    }

    /**
     * The check page: nothing about the document but the sender's name, so
     * a forwarded link shows nothing to whoever cannot pass the check.
     */
    private function check(SigningRequest $request, string $token): Response
    {
        return CurrentCompany::run($request->company_id, function () use ($request, $token): Response {
            $company = Company::query()->findOrFail($request->company_id);

            return $this->private(response()->view('signing.check', [
                'request' => $request,
                'token' => $token,
                'companyName' => $company->legal_name ?: $company->name,
                'brand' => Brand::for($company),
                'color' => Brand::readable(Brand::for($company)->color),
                'emailCode' => $request->verification === SignerCheck::EmailCode,
                'locked' => $request->locked_at !== null,
                // A test company sends no mail (ADR-032): the page says so instead of offering a code that never arrives.
                'testCompany' => $company->isTestCompany(),
                'maskedEmail' => self::masked($request->email),
                // A code that can still be typed: the page asks for it rather than offering to send one.
                'openCode' => $request->verification === SignerCheck::EmailCode && SigningVerificationCode::query()
                    ->where('signing_request_id', $request->id)->where('channel', SigningVerificationCode::LINK)
                    ->whereNull('verified_at')->where('expires_at', '>', now())->whereColumn('attempts', '<', 'max_attempts')->exists(),
            ]), page: true);
        });
    }

    /** a•••@example.se: enough for the signer to recognise the address, not to learn it. */
    public static function masked(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    private function documentChanged(SigningRequest $request): bool
    {
        return CurrentCompany::run($request->company_id, function () use ($request): bool {
            $document = $request->document();

            return ! $document instanceof Signable || ! $document->signingIsOpen() || ! hash_equals((string) $request->content_hash, $document->signingFingerprint());
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

            return $this->private(response()->view('signing.page', [
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
                // Several signers: the page says who else gets the names and times (ADR-046 S1).
                'several' => SigningEnvelopes::hasSeveralSigners($request->envelope_id),
                'vat' => $quote instanceof SalesDocument ? InvoiceCalculator::totals($quote->rows->map(fn ($row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all()) : null,
            ]), page: true);
        });
    }

    private function unavailable(string $reason, int $status, ?SigningRequest $request = null, bool $signedNow = false, bool $declinedNow = false): Response
    {
        $company = $request === null ? null : Company::query()->find($request->company_id);

        return $this->private(response()->view('signing.closed', [
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
