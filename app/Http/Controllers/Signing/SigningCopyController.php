<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Domain\Signing\CompletionFiles;
use App\Domain\Signing\CompletionLinks;
use App\Domain\Signing\DownloadCodes;
use App\Domain\Signing\Verification\CheckRefused;
use App\Models\Company;
use App\Models\SigningDownloadEvent;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page behind the link in a completion mail whose files were too large to
 * attach (ADR-046 amendment, gap 5, S13): /signera/kopia/{token}. The token is
 * the only key and is looked up by its hash; it is valid 14 days (7 for the company's and a confidential envelope's), until the sender closes it and gives
 * the files of its own kind (CompletionFiles) and nothing else. Private
 * (no-store), never indexed, no Referer, in the language the recipient was
 * given. An unknown token reveals nothing; an expired one says so.
 */
final class SigningCopyController
{
    public function show(Request $request, string $token): Response
    {
        $resolved = $this->resolve($token);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$link, $files] = $resolved;
        $company = Company::query()->find($link->company_id);

        // The files of a confidential envelope are held behind an e-mail code to the link's own address (ADR-046 S13 amendment).
        if (DownloadCodes::required($link) && ! DownloadCodes::unlocked($request->session(), $link)) {
            return $this->codePage($link, $company, $token);
        }

        return $this->private(response()->view('signing.copy', [
            'state' => 'files',
            'companyName' => $company instanceof Company ? ($company->legal_name ?: $company->name) : null,
            'color' => $company instanceof Company ? Brand::readable(Brand::for($company)->color) : '#1d4ed8',
            'token' => $token,
            'files' => array_map(fn (array $file): array => ['name' => $file['name'], 'size' => $file['path'] === null ? null : $this->size($link->company_id, $file['path'])], $files),
            'expires' => $link->expires_at->setTimezone(config('app.timezone')),
            'title' => (string) CurrentCompany::run($link->company_id, fn (): ?string => SigningEnvelope::query()->whereKey($link->envelope_id)->value('title')),
        ]));
    }

    /** "Skicka kod": the code to the link's own address, or the one sent a minute ago is reused. */
    public function sendCode(Request $request, string $token, DownloadCodes $codes): Response
    {
        $resolved = $this->resolve($token);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$link] = $resolved;

        if (! DownloadCodes::required($link) || DownloadCodes::unlocked($request->session(), $link)) {
            return $this->redirectToPage($token);
        }

        try {
            $codes->send($link);
        } catch (CheckRefused $refused) {
            return $this->redirectToPage($token)->withErrors(['code' => $this->refusal($refused)]);
        }

        return $this->redirectToPage($token)->with('signing-download-code', __('Vi har skickat en kod till :email.', ['email' => $this->masked($link->email)]));
    }

    /** The recipient types the code; a right one opens the downloads for this browser session for thirty minutes. */
    public function verify(Request $request, string $token, DownloadCodes $codes): Response
    {
        $resolved = $this->resolve($token);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$link] = $resolved;

        if (! DownloadCodes::required($link) || DownloadCodes::unlocked($request->session(), $link)) {
            return $this->redirectToPage($token);
        }

        try {
            $codes->verify($link, $request->input('code'));
        } catch (ValidationException $invalid) {
            return $this->redirectToPage($token)->withErrors($invalid->errors());
        } catch (CheckRefused $refused) {
            return $this->redirectToPage($token)->withErrors(['code' => $this->refusal($refused)]);
        }

        $request->session()->regenerate();
        DownloadCodes::grant($request->session(), $link);

        return $this->redirectToPage($token);
    }

    public function file(Request $request, string $token, int $file): Response
    {
        $resolved = $this->resolve($token);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$link, $files] = $resolved;

        if (DownloadCodes::required($link) && ! DownloadCodes::unlocked($request->session(), $link)) {
            return $this->redirectToPage($token);
        }

        abort_unless(isset($files[$file - 1]), 404);

        $entry = $files[$file - 1];
        CurrentCompany::run($link->company_id, function () use ($link, $file): void {
            SigningDownloadEvent::create(['company_id' => $link->company_id, 'download_link_id' => $link->id, 'file_index' => $file, 'downloaded_at' => now()]);
        });
        $link->increment('downloads');

        // The file is streamed (a stored file straight from the disk), with a name the headers can carry: å, ä and ö get an ASCII fallback.
        $stream = $entry['path'] === null ? null : Storage::disk(SigningRequest::DISK)->readStream($entry['path']);

        return $this->private(response()->streamDownload(function () use ($stream, $entry, $link): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);

                return;
            }

            echo CurrentCompany::run($link->company_id, fn (): string => CompletionFiles::bytes($entry));
        }, $entry['name'], ['Content-Type' => 'application/pdf']));
    }

    /**
     * The link and its files, or the answer to give instead.
     *
     * @return array{SigningDownloadLink, list<array{name: string, path: ?string, make: ?\Closure(): string}>}|Response
     */
    private function resolve(string $token): array|Response
    {
        $link = CompletionLinks::find($token);

        if (! $link instanceof SigningDownloadLink) {
            return $this->closed('gone', 404);
        }

        App::setLocale($link->language === 'en' ? 'en' : 'sv');

        if ($link->isRevoked()) {
            return $this->closed('revoked', 410, $link);
        }

        if ($link->isExpired()) {
            return $this->closed('expired', 410, $link);
        }

        $files = CurrentCompany::run($link->company_id, function () use ($link): array {
            $envelope = SigningEnvelope::query()->find($link->envelope_id);

            if (! $envelope instanceof SigningEnvelope) {
                return [];
            }

            $request = $link->signing_request_id === null ? null : SigningRequest::query()->find($link->signing_request_id);

            if ($request instanceof SigningRequest && ($link->kind === SigningDownloadLink::SIGNER || ($link->kind === SigningDownloadLink::COMPANY && $envelope->signers()->count() < 2))) {
                return CompletionFiles::forSigner($request);
            }

            return CompletionFiles::forEnvelope($envelope, $link->kind === SigningDownloadLink::COMPANY ? SigningDownloadLink::COMPANY : SigningDownloadLink::COPY);
        });

        return $files === [] ? $this->closed('gone', 404) : [$link, $files];
    }

    private function redirectToPage(string $token): RedirectResponse
    {
        return $this->private(redirect()->route('signing.copy', ['token' => $token]));
    }

    private function refusal(CheckRefused $refused): string
    {
        return $refused->reason === CheckRefused::LOCKED
            ? __('För många felaktiga försök. Be avsändaren skicka en ny länk.')
            : $refused->text();
    }

    /** The address as it is shown on the page: the first letter and the domain, so the page never gives it away to someone with only the link. */
    private function masked(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function codePage(SigningDownloadLink $link, ?Company $company, string $token): Response
    {
        return $this->private(response()->view('signing.copy', [
            'state' => 'code',
            'companyName' => $company instanceof Company ? ($company->legal_name ?: $company->name) : null,
            'color' => $company instanceof Company ? Brand::readable(Brand::for($company)->color) : '#1d4ed8',
            'token' => $token,
            'maskedEmail' => $this->masked($link->email),
            'codeSent' => DownloadCodes::hasOpenCode($link),
            'locked' => DownloadCodes::isLocked($link),
            'testCompany' => $company?->isTestCompany() === true,
            'minutes' => DownloadCodes::CODE_MINUTES,
        ]));
    }

    private function size(int $companyId, string $path): int
    {
        return CurrentCompany::run($companyId, fn (): int => CompletionFiles::storedBytes([['name' => '', 'path' => $path, 'make' => null]]));
    }

    private function closed(string $reason, int $status, ?SigningDownloadLink $link = null): Response
    {
        $company = $link instanceof SigningDownloadLink ? Company::query()->find($link->company_id) : null;

        return $this->private(response()->view('signing.copy', [
            'state' => $reason,
            // The sender's name only for a link that once was valid; an unknown token reveals nothing.
            'companyName' => $company instanceof Company ? ($company->legal_name ?: $company->name) : null,
            'color' => '#1d4ed8',
        ], $status));
    }

    /**
     * @template TResponse of Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    private function private(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if (! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'; form-action 'none'");
        }

        return $response;
    }
}
