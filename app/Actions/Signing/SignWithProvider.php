<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Pdf\ExternalSignatureRequest;
use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfEngineFailure;
use App\Domain\Signing\Pdf\PreparedSignature;
use App\Domain\Signing\Providers\ProviderCertificate;
use App\Domain\Signing\Providers\ProviderFailure;
use App\Domain\Signing\Providers\ProviderRegistry;
use App\Domain\Signing\Providers\ProviderRun;
use App\Domain\Signing\Providers\ProviderSigned;
use App\Domain\Signing\Providers\ProviderStep;
use App\Domain\Signing\SigningHost;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningToken;
use App\Domain\Signing\SigningUnavailable;
use App\Domain\Signing\Verification\SignerCheck;
use App\Models\SigningDocumentVersion;
use App\Models\SigningProvider;
use App\Models\SigningProviderSession;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/**
 * A signer signs at an external provider (ADR-046 amendment, gap 12,
 * package S17). The signer fills in the signing page as usual and presses
 * "Fortsätt till …"; then:
 *
 * 1. begin(): the form is checked exactly as a signature would be (under
 *    the signer's lock: consent, confirmation, the PDF, the document still
 *    open), the version shown is remembered, and the browser is sent to the
 *    provider (start()) with a fresh random state. The run's row holds the
 *    state's SHA-256 only; the form, the driver's working data and the link
 *    token are encrypted in it; the browser's own session holds the state's
 *    hash too, so a callback in another browser is refused.
 * 2. callback(), after identification: the driver answers the signer's
 *    certificate; the PDF engine prepares the signer's PAdES signature over
 *    the version shown (still the latest, else refused) and gives the digest
 *    of its signed attributes; the browser goes to the provider again to
 *    authorise exactly that digest.
 * 3. callback(), after authorisation: the driver answers the signature; the
 *    engine checks it against the certificate and the digest and puts it in
 *    the PDF; the file is validated (intact, valid, the same certificate);
 *    and SignAtLink signs with it in one transaction (ProviderSignatures).
 *
 * Any failure ends the run (FAILED with a code; secrets, state and the
 * prepared file are emptied) and sends the signer back to the page with a
 * Swedish message; nothing is signed. One run per signer at a time: a new
 * begin() ends an earlier one. Audit entries hold ids, the driver, the
 * stated level and failure codes, never a name, a token or a certificate.
 */
final class SignWithProvider
{
    /** The browser session key holding state hash => run id. */
    public const string SESSION_KEY = 'signing.provider_runs';

    public function __construct(
        private readonly SignAtLink $links,
        private readonly PdfEngine $engine,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the signing form
     * @return array{url: string, provider: string} the provider's address to send the browser to, and its name
     *
     * @throws SigningUnavailable
     * @throws ValidationException
     */
    public function begin(SigningRequest $request, #[SensitiveParameter] string $token, array $input, Session $browser): array
    {
        [$validated] = $this->links->validateForm($input);

        if ($request->verification !== SignerCheck::Provider || $request->signing_provider_id === null) {
            throw ValidationException::withMessages(['provider' => __('Den här handlingen skrivs inte under hos en extern tjänst.')]);
        }

        return CurrentCompany::run($request->company_id, function () use ($request, $token, $input, $validated, $browser): array {
            $config = SigningProvider::query()->active()->whereKey($request->signing_provider_id)->first();

            if (! $config instanceof SigningProvider || ! ProviderRegistry::usable() || ! in_array($config->driver, ProviderRegistry::keys(), true)) {
                throw ValidationException::withMessages(['provider' => (new ProviderFailure(ProviderFailure::UNAVAILABLE))->message()]);
            }

            // Checked first, under the locks, so nothing is started for a form that would be refused.
            $shown = DB::transaction(function () use ($request, $validated, $input): SigningDocumentVersion {
                $request->document(lock: true);
                $locked = $this->links->lockUsable($request);
                $this->links->checkLocked($locked, $validated);

                return $this->shownVersion($locked, $input);
            }, SignAtLink::ATTEMPTS);

            $state = self::state();
            $run = new ProviderRun($config, self::redirectUri(), $state, $request->language === 'en' ? 'en' : 'sv', documentCode: ProviderRun::documentCode($shown->sha256));

            try {
                $step = ProviderRegistry::for($config)->start($run);
            } catch (ProviderFailure $failure) {
                Audit::record('signing.provider_failed', $request, ['signing_request_id' => $request->id, 'provider_id' => $config->id, 'driver' => $config->driver, 'failure' => $failure->reason], $request->company_id);

                throw ValidationException::withMessages(['provider' => $failure->message()]);
            }

            if ($step->kind !== ProviderStep::REDIRECT || $step->url === null) {
                throw ValidationException::withMessages(['provider' => (new ProviderFailure(ProviderFailure::INVALID_ANSWER))->message()]);
            }

            $session = DB::transaction(function () use ($request, $config, $shown, $run, $state, $token, $input): SigningProviderSession {
                $request->document(lock: true);
                $locked = $this->links->lockUsable($request);

                // One run per signer: an earlier one that is still running ends here, and its prepared file goes after the commit.
                foreach (SigningProviderSession::query()->where('signing_request_id', $locked->id)->whereIn('step', [SigningProviderSession::SERVICE, SigningProviderSession::CREDENTIAL])->lockForUpdate()->get() as $earlier) {
                    $this->end($earlier, ProviderFailure::SUPERSEDED);
                    $ended = $earlier->id;
                    DB::afterCommit(fn () => $this->removePrepared($ended));
                }

                $session = SigningProviderSession::create([
                    'signing_request_id' => $locked->id,
                    'provider_id' => $config->id,
                    'step' => SigningProviderSession::SERVICE,
                    'state_hash' => hash('sha256', $state),
                    'secrets' => ['driver' => $run->secrets, 'token' => $token, 'form' => self::form($input)],
                    'shown_sha256' => $shown->sha256,
                    'claimed_level' => $config->claimed_level,
                    'provider_name' => $config->name,
                    'provider_service_name' => $config->service_name,
                    'expires_at' => CarbonImmutable::now()->addMinutes(self::minutes()),
                ]);

                Audit::record('signing.provider_started', $session, ['signing_request_id' => $locked->id, 'provider_session_id' => $session->id, 'provider_id' => $config->id, 'driver' => $config->driver], $locked->company_id);

                return $session;
            }, SignAtLink::ATTEMPTS);

            $this->remember($browser, $state, $session->id);

            return ['url' => $step->url, 'provider' => $config->name];
        });
    }

    /**
     * A browser step came back from the provider. Answers where the browser goes next: the provider again, or
     * back to the signing page (signed, or with an error).
     *
     * @param  array<string, mixed>  $query
     * @return array{redirect: string, token: ?string, signed: bool, error: ?string}
     */
    public function callback(array $query, Session $browser, ?string $ip, ?string $userAgent): array
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $stateHash = hash('sha256', $state);

        // The state is the only key of this public return address: looked up across companies by its hash alone (ADR-046 S17, as the signing token).
        $session = $state === '' || strlen($state) > 200 ? null : CompanyScope::bypass(fn (): ?SigningProviderSession => SigningProviderSession::query()->where('state_hash', $stateHash)->first());

        if (! $session instanceof SigningProviderSession || ! hash_equals((string) $session->state_hash, $stateHash)) {
            return ['redirect' => '', 'token' => null, 'signed' => false, 'error' => (new ProviderFailure(ProviderFailure::EXPIRED))->message()];
        }

        return CurrentCompany::run($session->company_id, function () use ($session, $stateHash, $query, $browser, $ip, $userAgent): array {
            $token = (string) ($session->secrets['token'] ?? '');

            try {
                if (($browser->get(self::SESSION_KEY)[$stateHash] ?? null) !== $session->id) {
                    throw new ProviderFailure(ProviderFailure::WRONG_BROWSER);
                }

                if (! $session->isRunning() || $session->expires_at->isPast()) {
                    throw new ProviderFailure(ProviderFailure::EXPIRED);
                }

                $config = SigningProvider::query()->active()->whereKey($session->provider_id)->first();

                if (! $config instanceof SigningProvider || ! ProviderRegistry::usable() || ! in_array($config->driver, ProviderRegistry::keys(), true)) {
                    throw new ProviderFailure(ProviderFailure::UNAVAILABLE);
                }

                $signer = SigningRequest::query()->findOrFail($session->signing_request_id);

                if ($signer->effectiveStatus() !== SigningStatus::Pending) {
                    throw new ProviderFailure(ProviderFailure::EXPIRED);
                }

                $callback = array_filter(array_map(fn (mixed $value): ?string => is_string($value) ? $value : null, $query), is_string(...));
                $next = self::state();
                $run = new ProviderRun($config, self::redirectUri(), $next, $signer->language === 'en' ? 'en' : 'sv', (array) ($session->secrets['driver'] ?? []), ProviderRun::documentCode($session->shown_sha256));
                $step = ProviderRegistry::for($config)->collect($run, $callback);

                return $session->step === SigningProviderSession::SERVICE
                    ? $this->authorize($session, $signer, $config, $run, $step, $next, $browser, $stateHash, $token)
                    : $this->finish($session, $signer, $step, $token, $ip, $userAgent, $browser, $stateHash);
            } catch (ProviderFailure $failure) {
                // Whoever holds the state in another browser learns nothing (no link to the signing page) and
                // cannot end the signer's run: it is left for the signer's own browser, or to expire.
                if ($failure->reason === ProviderFailure::WRONG_BROWSER) {
                    return ['redirect' => '', 'token' => null, 'signed' => false, 'error' => $failure->message()];
                }

                $this->fail($session, $failure->reason, $browser, $stateHash);

                return ['redirect' => '', 'token' => $token === '' ? null : $token, 'signed' => false, 'error' => $failure->message()];
            } catch (SigningUnavailable $unavailable) {
                $this->fail($session, ProviderFailure::EXPIRED, $browser, $stateHash);

                throw $unavailable;
            } catch (ValidationException $exception) {
                $this->fail($session, ProviderFailure::DOCUMENT_CHANGED, $browser, $stateHash);

                return ['redirect' => '', 'token' => $token === '' ? null : $token, 'signed' => false, 'error' => (string) collect($exception->errors())->flatten()->first()];
            }
        });
    }

    /**
     * After identification: the certificate is known, the signer's signature is prepared, and the browser goes to
     * the provider to authorise its digest.
     *
     * @return array{redirect: string, token: ?string, signed: bool, error: ?string}
     */
    private function authorize(SigningProviderSession $session, SigningRequest $signer, SigningProvider $config, ProviderRun $run, ProviderStep $step, string $next, Session $browser, string $stateHash, string $token): array
    {
        if ($step->kind !== ProviderStep::CERTIFICATE || ! $step->certificate instanceof ProviderCertificate) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'step');
        }

        $certificate = $step->certificate;
        $shown = $this->latestShown($session, $signer);
        $disk = Storage::disk(SigningRequest::DISK);
        $request = self::externalRequest($signer, $certificate->chain, $certificate->algorithm);
        $temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-provider-'.Str::uuid()->toString().'.pdf';

        try {
            $prepared = $this->engine->prepareExternalSignature($disk->path($shown->path), $temporary, $request);
            $path = 'crm-signing/'.$signer->company_id.'/provider/'.Str::uuid()->toString().'.pdf';
            $disk->put($path, (string) file_get_contents($temporary));
        } catch (PdfEngineException) {
            throw new ProviderFailure(ProviderFailure::ENGINE);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        try {
            $authorize = ProviderRegistry::for($config)->authorizeDigest($run, $prepared->digest);
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        if ($authorize->kind !== ProviderStep::REDIRECT || $authorize->url === null) {
            $disk->delete($path);

            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'step');
        }

        $moved = DB::transaction(function () use ($session, $stateHash, $next, $run, $prepared, $certificate, $path, $token): bool {
            $locked = SigningProviderSession::query()->whereKey($session->id)->where('step', SigningProviderSession::SERVICE)->where('state_hash', $stateHash)->lockForUpdate()->first();

            if (! $locked instanceof SigningProviderSession) {
                return false;
            }

            $locked->forceFill([
                'step' => SigningProviderSession::CREDENTIAL,
                'state_hash' => hash('sha256', $next),
                'secrets' => [
                    ...(array) $locked->secrets,
                    'driver' => $run->secrets,
                    'prepared' => $prepared->toArray(),
                    'certificates' => array_map(base64_encode(...), $certificate->chain),
                    'algorithm' => $certificate->algorithm,
                    'token' => $token,
                ],
                'prepared_path' => $path,
                'digest_sha256' => bin2hex($prepared->digest),
                'certificate_name' => $certificate->commonName,
                'certificate_issuer' => $certificate->issuerName,
                'certificate_serial' => $certificate->serial,
                'certificate_sha256' => $certificate->sha256,
                'certificate_not_after' => $certificate->notAfter,
            ])->save();

            return true;
        });

        if (! $moved) {
            $disk->delete($path);

            throw new ProviderFailure(ProviderFailure::EXPIRED);
        }

        $this->forget($browser, $stateHash);
        $this->remember($browser, $next, $session->id);

        return ['redirect' => (string) $authorize->url, 'token' => $token, 'signed' => false, 'error' => null];
    }

    /**
     * After authorisation: the provider's signature goes into the prepared file, is checked, and the signer signs.
     *
     * @return array{redirect: string, token: ?string, signed: bool, error: ?string}
     */
    private function finish(SigningProviderSession $session, SigningRequest $signer, ProviderStep $step, string $token, ?string $ip, ?string $userAgent, Session $browser, string $stateHash): array
    {
        if ($step->kind !== ProviderStep::SIGNATURE || $step->signature === null) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'step');
        }

        $secrets = (array) $session->secrets;
        $disk = Storage::disk(SigningRequest::DISK);
        $shown = $this->latestShown($session, $signer);
        $preparedPath = (string) $session->prepared_path;
        $prepared = PreparedSignature::fromArray($disk->path($preparedPath), (array) ($secrets['prepared'] ?? []));

        if ($preparedPath === '' || ! $disk->exists($preparedPath) || ! hash_equals($prepared->sha256, (string) hash_file('sha256', $disk->path($preparedPath)))) {
            throw new ProviderFailure(ProviderFailure::EXPIRED, 'prepared');
        }

        $chain = array_values(array_map(fn (mixed $value): string => (string) base64_decode((string) $value, true), (array) ($secrets['certificates'] ?? [])));
        $request = self::externalRequest($signer, $chain, (string) ($secrets['algorithm'] ?? ''));
        $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-provider-'.Str::uuid()->toString().'.pdf';

        try {
            try {
                $file = $this->engine->finishExternalSignature($disk->path($shown->path), $prepared, $request, $step->signature, $output);
                $report = $this->engine->validate($file->path);
            } catch (PdfEngineException $exception) {
                throw new ProviderFailure($exception->failure === PdfEngineFailure::SignatureInvalid ? ProviderFailure::SIGNATURE_INVALID : ProviderFailure::ENGINE);
            }

            // The file must carry the signer's own signature, intact and valid, with the certificate the provider named.
            $own = collect($report->signatures)->firstWhere('field', $request->fieldName);

            if (! is_array($own) || ! $own['intact'] || ! $own['valid'] || ! hash_equals((string) $session->certificate_sha256, (string) $own['certificate_sha256'])) {
                throw new ProviderFailure(ProviderFailure::SIGNATURE_INVALID);
            }

            $this->links->sign($signer, (array) ($secrets['form'] ?? []), $ip, $userAgent, new ProviderSigned($session->id, $file->path, $file->sha256, $file->bytes));
        } finally {
            if (is_file($output)) {
                @unlink($output);
            }
        }

        $this->forget($browser, $stateHash);
        $this->removePrepared($session->id);
        Audit::record('signing.provider_signed', $session, ['signing_request_id' => $signer->id, 'provider_session_id' => $session->id, 'provider_id' => $session->provider_id, 'claimed_level' => $session->claimed_level->value], $signer->company_id);

        return ['redirect' => '', 'token' => $token, 'signed' => true, 'error' => null];
    }

    /** The version the run was prepared over, which must still be the latest one. */
    private function latestShown(SigningProviderSession $session, SigningRequest $signer): SigningDocumentVersion
    {
        $shown = $signer->envelope_id === null ? null : SigningFields::latestVersion($signer->envelope_id);

        if (! $shown instanceof SigningDocumentVersion || ! hash_equals($session->shown_sha256, $shown->sha256)) {
            throw new ProviderFailure(ProviderFailure::DOCUMENT_CHANGED);
        }

        return $shown;
    }

    /**
     * The version the signer was shown when they pressed the button: the latest, and for an envelope with fields
     * the one the page named. A signer who signs at a provider has no fields of their own (refused at sending).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function shownVersion(SigningRequest $locked, array $input): SigningDocumentVersion
    {
        $shown = $locked->envelope_id === null ? null : SigningFields::latestVersion($locked->envelope_id);

        if (! $shown instanceof SigningDocumentVersion) {
            throw new SigningUnavailable(SigningUnavailable::GONE);
        }

        $named = strtolower((string) ($input['revision_sha256'] ?? ''));

        if ($named !== '' && ! hash_equals($shown->sha256, $named)) {
            throw ValidationException::withMessages(['provider' => (new ProviderFailure(ProviderFailure::DOCUMENT_CHANGED))->message()]);
        }

        return $shown;
    }

    /**
     * The signer's PAdES signature: one field per signer, the certificate chain the provider returned.
     *
     * @param  list<string>  $chain
     */
    private static function externalRequest(SigningRequest $signer, array $chain, string $algorithm): ExternalSignatureRequest
    {
        try {
            return new ExternalSignatureRequest($chain, $algorithm, 'NonoxSigner'.$signer->id, 'Signerad / Signed');
        } catch (InvalidArgumentException) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'certificate');
        }
    }

    private function fail(SigningProviderSession $session, string $reason, Session $browser, string $stateHash): void
    {
        $this->forget($browser, $stateHash);

        $ended = DB::transaction(function () use ($session, $reason, $stateHash): bool {
            $locked = SigningProviderSession::query()->whereKey($session->id)->lockForUpdate()->first();

            // A duplicate or parallel callback with the same state never ends a run that another request has
            // moved on (a new state) or is finishing: only the step this request answered is ended.
            if (! $locked instanceof SigningProviderSession || ! $locked->isRunning() || $locked->state_hash === null || ! hash_equals($locked->state_hash, $stateHash)) {
                return false;
            }

            $this->end($locked, $reason);

            return true;
        });

        if ($ended) {
            $this->removePrepared($session->id);
        }
    }

    /** Ends a running run: FAILED with a code, its secrets and state emptied. The caller holds its row lock. */
    private function end(SigningProviderSession $session, string $reason): void
    {
        $session->forceFill(['step' => SigningProviderSession::FAILED, 'failure' => mb_substr($reason, 0, 40), 'secrets' => null, 'state_hash' => null])->save();

        Audit::record('signing.provider_failed', $session, ['signing_request_id' => $session->signing_request_id, 'provider_session_id' => $session->id, 'provider_id' => $session->provider_id, 'failure' => $reason], $session->company_id);
    }

    /** The prepared file of a run that has ended, deleted and forgotten (the trigger allows only emptying it). */
    public function removePrepared(int $sessionId): void
    {
        $session = SigningProviderSession::query()->whereKey($sessionId)->first();

        if (! $session instanceof SigningProviderSession || $session->prepared_path === null || $session->isRunning()) {
            return;
        }

        Storage::disk(SigningRequest::DISK)->delete($session->prepared_path);
        $session->forceFill(['prepared_path' => null])->save();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function form(array $input): array
    {
        return array_intersect_key($input, array_flip(['name', 'title', 'accept', 'method', 'signature', 'typed_signature', 'document_sha256', 'acceptance_text', 'acceptance_mac', 'revision_sha256']));
    }

    private function remember(Session $browser, string $state, int $sessionId): void
    {
        $runs = (array) $browser->get(self::SESSION_KEY, []);
        $runs[hash('sha256', $state)] = $sessionId;
        // A handful at most: older ones have ended or expired.
        $browser->put(self::SESSION_KEY, array_slice($runs, -5, null, true));
    }

    private function forget(Session $browser, string $stateHash): void
    {
        $runs = (array) $browser->get(self::SESSION_KEY, []);
        unset($runs[$stateHash]);
        $browser->put(self::SESSION_KEY, $runs);
    }

    /** 256 random bits, URL-safe: the OAuth state of one browser step (RFC 6749 10.12). */
    private static function state(): string
    {
        return SigningToken::generate()['token'];
    }

    /** Where every provider sends the browser back: one fixed address, registered with the provider. */
    public static function redirectUri(): string
    {
        $path = route('signing.provider.callback', [], false);
        $root = SigningHost::root();

        return $root === null ? url($path) : $root.$path;
    }

    public static function minutes(): int
    {
        return max(5, min(120, (int) config('nonox.signing.providers.session_minutes', 30)));
    }
}
