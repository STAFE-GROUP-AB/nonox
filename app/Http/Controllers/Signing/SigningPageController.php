<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Actions\Signing\RecordSigningConsent;
use App\Actions\Signing\SignAtLink;
use App\Actions\Signing\SignWithProvider;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Signing\Fields\PageImages;
use App\Domain\Signing\Fields\SignerFields;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Providers\ProviderRegistry;
use App\Domain\Signing\Providers\ProviderRun;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningConsent;
use App\Domain\Signing\SigningEnvelopes;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningUnavailable;
use App\Domain\Signing\Verification\CheckRefused;
use App\Domain\Signing\Verification\SignerCheck;
use App\Domain\Signing\Verification\SignerChecks;
use App\Domain\Signing\Verification\SignerPass;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningDocumentVersion;
use App\Models\SigningField;
use App\Models\SigningProvider;
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
 *
 * An envelope with fields (gap 2, S6) shows the document's pages as images
 * rendered on the server (/signera/{token}/sida/{n}, same origin, behind the
 * token and the check), with the signer's fields over them and as a list of
 * labelled controls; no PDF viewer, so the CSP stays as it is.
 *
 * After any check and before the document, each signer accepts the consent
 * to electronic records once (gap 7, S4): until then the page, the PDF and
 * the page images are not shown. A signer who will not sign electronically
 * is told to contact the sender, and the sender is told.
 */
final class SigningPageController
{
    public function __construct(
        private readonly SignAtLink $links,
        private readonly SignerChecks $checks,
        private readonly PageImages $images,
        private readonly RecordSigningConsent $consents,
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

        // The consent to electronic records comes before the document (ADR-046 S4); opening is recorded only after it.
        if ($request->consent_at === null) {
            return $this->consent($request, $token);
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

        $reason = $request instanceof SigningRequest ? $this->links->unavailable($request) : SigningUnavailable::GONE;

        if (! $request instanceof SigningRequest || ! in_array($reason, [null, SigningUnavailable::SIGNED], true)) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        // A checked signer's PDF only after the check in this browser, also once signed: a forwarded link shows nothing.
        // An open link shows it only after the consent (ADR-046 S4); a signer who signed before the consent existed keeps their PDF.
        if (! SignerPass::has(session()->driver(), $request, $token) || ($reason === null && $request->consent_at === null)) {
            return redirect()->route('signing.show', ['token' => $token]);
        }

        // With fields (ADR-046 S6) the signer is shown the latest revision, with earlier signers' values; once signed, their own.
        $path = CurrentCompany::run($request->company_id, fn (): string => $this->hasFields($request) ? (SignerFields::shownVersion($request)->path ?? $request->document_path) : $request->document_path);
        $bytes = Storage::disk(SigningRequest::DISK)->get($path);
        abort_unless(is_string($bytes), 404);

        return $this->private(response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$request->document_name.'"',
        ]));
    }

    /** One page of the version the signer is shown, as a PNG (ADR-046 S6): only for an envelope with fields, only after the check. */
    public function pageImage(string $token, int $page): Response
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest || $this->links->unavailable($request) !== null || ! SignerPass::has(session()->driver(), $request, $token) || $request->consent_at === null) {
            abort(404);
        }

        $bytes = CurrentCompany::run($request->company_id, function () use ($request, $page): ?string {
            $version = $this->hasFields($request) ? SigningFields::latestVersion((string) $request->envelope_id) : null;

            if (! $version instanceof SigningDocumentVersion) {
                return null;
            }

            // Only images the queued render has made; the request never starts the engine.
            $pages = $this->images->pages($version) ?? [];

            return in_array($page, array_column($pages, 'page'), true) ? $this->images->image($version, $page) : null;
        });

        abort_unless(is_string($bytes), 404);

        $response = $this->private(response($bytes, 200, ['Content-Type' => 'image/png']));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'");

        return $response;
    }

    /** "Jag godkänner att handlingen skrivs under elektroniskt": stored once, then the document is shown (ADR-046 S4). */
    public function giveConsent(Request $http, string $token): Response|RedirectResponse
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        if (! SignerPass::has($http->session(), $request, $token)) {
            return $this->checkExpired($request, $token);
        }

        try {
            $this->consents->give($request, $http->only(['consent', 'consent_version', 'consent_text_sha256']));
        } catch (SigningUnavailable $unavailable) {
            return $this->unavailable($unavailable->reason, 410, $request);
        } catch (ValidationException $exception) {
            return redirect()->route('signing.show', ['token' => $token])->withErrors($exception->errors());
        }

        return redirect()->route('signing.show', ['token' => $token]);
    }

    /** The signer will not sign electronically: told to contact the sender, and the sender is told (ADR-046 S4). */
    public function refuseConsent(Request $http, string $token): Response|RedirectResponse
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        if (! SignerPass::has($http->session(), $request, $token)) {
            return $this->checkExpired($request, $token);
        }

        try {
            $answered = $this->consents->refuse($request);
        } catch (SigningUnavailable $unavailable) {
            return $this->unavailable($unavailable->reason, 410, $request);
        } catch (ValidationException $exception) {
            return redirect()->route('signing.show', ['token' => $token])->withErrors($exception->errors());
        }

        // Accepted already (another tab, a double click): nothing was refused and the sender was told nothing.
        if ($answered->consent_at !== null) {
            return redirect()->route('signing.show', ['token' => $token]);
        }

        return CurrentCompany::run($request->company_id, function () use ($request, $token): Response {
            $company = Company::query()->findOrFail($request->company_id);

            return $this->private(response()->view('signing.consent', [
                ...$this->consentData($request, $token, $company),
                'refused' => true,
            ]), page: true);
        });
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
            $this->links->sign($request, $http->only(['name', 'title', 'accept', 'method', 'signature', 'typed_signature', 'document_sha256', 'acceptance_text', 'acceptance_mac', 'fields', 'radio', 'initials_method', 'initials', 'typed_initials', 'revision_sha256']), $http->ip(), $http->userAgent());
        } catch (SigningUnavailable $unavailable) {
            if ($unavailable->reason === SigningUnavailable::CHANGED) {
                $this->links->revokeStale($request);
            }

            return $this->unavailable($unavailable->reason, 410, $request);
        } catch (ValidationException $exception) {
            // The drawn signature and initials are not sent back into the form; the signer draws them again.
            return redirect()->route('signing.show', ['token' => $token])
                ->withErrors($exception->errors())
                ->withInput($http->except(['signature', 'initials', '_token']));
        }

        return $this->unavailable(SigningUnavailable::SIGNED, 200, $request->refresh(), signedNow: true);
    }

    /**
     * "Fortsätt till …" for a signer who signs at an external provider (ADR-046 S17): the form is checked as a
     * signature would be, then the browser goes to the provider. Nothing is signed here.
     */
    public function startProvider(Request $http, string $token, SignWithProvider $provider): Response|RedirectResponse
    {
        $request = $this->links->find($token);

        if (! $request instanceof SigningRequest) {
            return $this->unavailable(SigningUnavailable::GONE, 404);
        }

        if (! SignerPass::has($http->session(), $request, $token)) {
            return $this->checkExpired($request, $token);
        }

        try {
            $started = $provider->begin($request, $token, $http->only(['name', 'title', 'accept', 'method', 'signature', 'typed_signature', 'document_sha256', 'acceptance_text', 'acceptance_mac', 'revision_sha256']), $http->session());
        } catch (SigningUnavailable $unavailable) {
            if ($unavailable->reason === SigningUnavailable::CHANGED) {
                $this->links->revokeStale($request);
            }

            return $this->unavailable($unavailable->reason, 410, $request);
        } catch (ValidationException $exception) {
            return redirect()->route('signing.show', ['token' => $token])
                ->withErrors($exception->errors())
                ->withInput($http->except(['signature', 'initials', '_token']));
        }

        // The provider's own page, an https address outside NONOX: a plain navigation from an interim page, since the
        // signing pages allow a form to post to NONOX only (form-action 'self' applies to a redirect after a post).
        return $this->private(response()->view('signing.provider-redirect', ['url' => $started['url'], 'provider' => $started['provider']]), page: true);
    }

    /** Where a provider sends the browser back (ADR-046 S17): the next step at the provider, or the signing page. */
    public function providerCallback(Request $http, SignWithProvider $provider): Response|RedirectResponse
    {
        try {
            $next = $provider->callback($http->query(), $http->session(), $http->ip(), $http->userAgent());
        } catch (SigningUnavailable $unavailable) {
            return $this->unavailable($unavailable->reason, 410);
        }

        if ($next['redirect'] !== '') {
            return redirect()->away($next['redirect']);
        }

        if ($next['token'] === null) {
            // An unknown or used state: nothing about any document is shown.
            return $this->unavailable(SigningUnavailable::GONE, 410);
        }

        if ($next['signed']) {
            $request = $this->links->find($next['token']);

            return $request instanceof SigningRequest
                ? $this->unavailable(SigningUnavailable::SIGNED, 200, $request->refresh(), signedNow: true)
                : redirect()->route('signing.show', ['token' => $next['token']]);
        }

        return redirect()->route('signing.show', ['token' => $next['token']])->withErrors(['provider' => (string) $next['error']]);
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

    /** The consent to electronic records, shown after any check and before the document (ADR-046 S4). */
    private function consent(SigningRequest $request, string $token): Response
    {
        return CurrentCompany::run($request->company_id, function () use ($request, $token): Response {
            $company = Company::query()->findOrFail($request->company_id);

            return $this->private(response()->view('signing.consent', [
                ...$this->consentData($request, $token, $company),
                'refused' => false,
            ]), page: true);
        });
    }

    /** @return array<string, mixed> */
    private function consentData(SigningRequest $request, string $token, Company $company): array
    {
        $contact = implode(', ', array_filter([$company->org_number ? (string) __('org.nr :number', ['number' => $company->org_number]) : null, $company->email ?: null]));

        return [
            // Who is responsible for the answer and why it is kept, as on the document page (art. 13).
            'controller' => ($company->legal_name ?: $company->name).($contact === '' ? '' : " ({$contact})"),
            // A test company sends no mail (ADR-032): the page says the copy the text promises does not come.
            'testCompany' => $company->isTestCompany(),
            'request' => $request,
            'token' => $token,
            'companyName' => $company->legal_name ?: $company->name,
            'brand' => Brand::for($company),
            'color' => Brand::readable(Brand::for($company)->color),
            'title' => SigningPdfs::title($request),
            'consentText' => SigningConsent::text(),
            'consentVersion' => SigningConsent::VERSION,
            'consentSha256' => SigningConsent::sha256(),
        ];
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
                // Fields in the PDF (ADR-046 S6): null for an envelope without fields, whose page is as it always was.
                'fields' => SignerFields::for($request, $this->images),
                // Signing at an external provider (ADR-046 S17): its name, the level the company states for it, and whether it can be used now.
                'provider' => $this->providerFor($request),
                'vat' => $quote instanceof SalesDocument ? InvoiceCalculator::totals($quote->rows->map(fn ($row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all()) : null,
            ]), page: true);
        });
    }

    /** @return ?array{name: string, code: string, usable: bool} the level the company states is never shown here (legal review F1) */
    private function providerFor(SigningRequest $request): ?array
    {
        if ($request->verification !== SignerCheck::Provider || $request->signing_provider_id === null) {
            return null;
        }

        $provider = SigningProvider::query()->find($request->signing_provider_id);

        if (! $provider instanceof SigningProvider) {
            return null;
        }

        return [
            'name' => $provider->name,
            // The code of the version the signer is shown and signs (legal review F7), also sent to the provider.
            'code' => ProviderRun::documentCode((string) (SigningFields::latestVersion((string) $request->envelope_id)->sha256 ?? $request->document_sha256)),
            'usable' => $provider->retired_at === null && ProviderRegistry::usable() && in_array($provider->driver, ProviderRegistry::keys(), true),
        ];
    }

    private function hasFields(SigningRequest $request): bool
    {
        return $request->envelope_id !== null && SigningField::query()->where('envelope_id', $request->envelope_id)->exists();
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
