<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SignatureImage;
use App\Domain\Signing\SigningEnvelopes;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningFollowUp;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningToken;
use App\Domain\Signing\SigningUnavailable;
use App\Events\QuoteSigned;
use App\Jobs\FollowUpSigning;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningRequestMail;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningLinkToken;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What the signer does at the public link (ADR-046, core since ADR-075
 * §7): open it, sign it or decline it, for any Signable document. No account: the unguessable token is the only key, and it is
 * looked up by its SHA-256 alone, so the page can never reach another
 * company's data. Signing and declining lock the document and then the
 * request row (the order every signing path takes; a deadlock is retried),
 * so a double submit or two tabs sign once; a link is used once.
 *
 * Signing binds to the exact PDF shown: the form carries the SHA-256 the
 * page showed, and it must equal the one stored when the link was sent and
 * the stored file's own hash. A quote that changed after it was sent cannot
 * be signed (QuoteFingerprint): the link is revoked instead.
 *
 * In an envelope with several signers (ADR-046 amendment, gap 1) a
 * signature completes the envelope or opens the next step, whose links are
 * mailed after commit; only a completed envelope marks the document signed
 * and runs the follow-up, so a quote becomes an order when everyone has
 * signed. A decline ends the envelope for everyone.
 */
final class SignAtLink
{
    /** Retries of a signing transaction that meets a deadlock. */
    public const int ATTEMPTS = 3;

    public function __construct(
        private readonly SigningEnvelopes $envelopes,
        private readonly SigningChain $chain,
    ) {}

    /** The request behind a token, in any company, or null. */
    public function find(string $token): ?SigningRequest
    {
        if (! SigningToken::isWellFormed($token)) {
            return null;
        }

        $hash = SigningToken::hash($token);

        // The token is the only key on this public page: the lookup crosses companies by its hash, and nothing else is matched.
        $request = CompanyScope::bypass(fn (): ?SigningRequest => SigningRequest::query()->where('token_hash', $hash)->first());

        if ($request instanceof SigningRequest && hash_equals($request->token_hash, $hash)) {
            return $request;
        }

        // A signer in an envelope with several signers holds a link from signing_link_tokens (ADR-046 S1), matched the same way.
        $link = CompanyScope::bypass(fn (): ?SigningLinkToken => SigningLinkToken::query()->where('token_hash', $hash)->first());

        if (! $link instanceof SigningLinkToken || ! hash_equals($link->token_hash, $hash)) {
            return null;
        }

        $request = CompanyScope::bypass(fn (): ?SigningRequest => SigningRequest::query()->whereKey($link->signing_request_id)->where('company_id', $link->company_id)->first());

        // A token revoked while its signer is still pending (a newer link was sent) no longer opens anything.
        return $request instanceof SigningRequest && ($link->revoked_at === null || $request->status !== SigningStatus::Pending) ? $request : null;
    }

    /**
     * Why the link cannot be used, or null when it can: the stored status,
     * expiry, the module, an archived company.
     */
    public function unavailable(SigningRequest $request): ?string
    {
        $company = Company::query()->find($request->company_id);

        if (! $company instanceof Company || $company->isArchived()) {
            return SigningUnavailable::GONE;
        }

        // The document's own module must be on (an agreement needs CRM); a quote needs none.
        $document = CurrentCompany::run($company, fn (): ?Signable => $request->document());

        if (! $document instanceof Signable || ! SendForSigning::moduleOn($document, $company)) {
            return SigningUnavailable::GONE;
        }

        return match ($request->effectiveStatus()) {
            SigningStatus::Pending => null,
            SigningStatus::Signed => SigningUnavailable::SIGNED,
            SigningStatus::Declined => SigningUnavailable::DECLINED,
            SigningStatus::Revoked => $this->revokedReason($request),
            SigningStatus::Expired => SigningUnavailable::EXPIRED,
        };
    }

    /** A revoked link in an envelope with several signers says why: withdrawn by the sender, or declined by another signer. */
    private function revokedReason(SigningRequest $request): string
    {
        if ($request->envelope_id === null) {
            return SigningUnavailable::REVOKED;
        }

        // Read across companies like the token lookup: the public page runs outside any company, matched on the request's own envelope and company.
        $signers = CompanyScope::bypass(fn (): int => SigningRequest::query()->where('envelope_id', $request->envelope_id)->where('company_id', $request->company_id)->count());

        if ($signers < 2) {
            return SigningUnavailable::REVOKED;
        }

        $status = CompanyScope::bypass(fn (): mixed => SigningEnvelope::query()->whereKey($request->envelope_id)->where('company_id', $request->company_id)->value('status'));

        return match ($status) {
            EnvelopeStatus::Voided => SigningUnavailable::VOIDED,
            EnvelopeStatus::Declined => SigningUnavailable::ENVELOPE_DECLINED,
            default => SigningUnavailable::REVOKED,
        };
    }

    /** The first visit is an event (without IP address or browser: opening is not an act of the signer's will). */
    public function open(SigningRequest $request): void
    {
        CurrentCompany::run($request->company_id, function () use ($request): void {
            if ($request->opened_at !== null) {
                return;
            }

            DB::transaction(function () use ($request): void {
                // The document first, then the request and its envelope (the chain's lock): the order every signing path takes.
                $request->document(lock: true);
                $updated = SigningRequest::query()->whereKey($request->id)->whereNull('opened_at')->where('status', SigningStatus::Pending->value)->update(['opened_at' => now()]);

                if ($updated === 1) {
                    $this->chain->record($request, SigningEventType::Opened);
                }
            }, self::ATTEMPTS);
        });
    }

    /** An expired link that is visited gets its EXPIRED event once. */
    public function noteExpired(SigningRequest $request): void
    {
        CurrentCompany::run($request->company_id, function () use ($request): void {
            DB::transaction(function () use ($request): void {
                // The document first, then the request and its envelope (the chain's lock): the order every signing path takes.
                $request->document(lock: true);
                $locked = SigningRequest::query()->whereKey($request->id)->lockForUpdate()->first();

                if (! $locked instanceof SigningRequest || $locked->effectiveStatus() !== SigningStatus::Expired) {
                    return;
                }

                // One expiry event per envelope (ADR-046 S9): signing:remind records it, or the first signer to open the expired link.
                $recorded = SigningEvent::query()
                    ->when(
                        $locked->envelope_id === null,
                        fn ($query) => $query->where('signing_request_id', $locked->id),
                        fn ($query) => $query->whereIn('signing_request_id', SigningRequest::query()->where('envelope_id', $locked->envelope_id)->select('id')),
                    )
                    ->where('event', SigningEventType::Expired->value)
                    ->exists();

                if (! $recorded) {
                    $this->chain->record($locked, SigningEventType::Expired);
                }
            }, self::ATTEMPTS);
        });
    }

    /**
     * @param  array<string, mixed>  $input  name, title, accept, method (DRAWN|TYPED), signature (PNG data URL), typed_signature, document_sha256
     *
     * @throws SigningUnavailable when the link cannot be used (any more)
     * @throws ValidationException
     */
    public function sign(SigningRequest $request, array $input, ?string $ip, ?string $userAgent): SigningRequest
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'title' => ['nullable', 'string', 'max:120', 'regex:/^[^\p{C}]*$/u'],
            'accept' => ['accepted'],
            'method' => ['required', 'in:'.SigningRequest::DRAWN.','.SigningRequest::TYPED],
            'signature' => ['nullable', 'string', 'max:300000'],
            'typed_signature' => ['nullable', 'string', 'max:120'],
            'document_sha256' => ['required', 'string', 'size:64'],
            'acceptance_text' => ['required', 'string', 'max:500'],
            'acceptance_mac' => ['required', 'string', 'size:64'],
        ], [
            'accept.accepted' => __('Bekräfta att du har läst och godkänner dokumentet.'),
            'name.required' => __('Skriv ditt namn.'),
            'name.regex' => __('Namnet innehåller tecken som inte går att använda.'),
        ], ['name' => __('namn'), 'title' => __('titel')])->validate();

        $name = trim((string) $validated['name']);
        $image = null;

        if ($validated['method'] === SigningRequest::DRAWN) {
            $image = SignatureImage::base64((string) ($validated['signature'] ?? ''));
        } elseif (mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($validated['typed_signature'] ?? ''))) ?? '') !== mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? '')) {
            throw ValidationException::withMessages(['typed_signature' => __('Skriv ditt namn exakt som i namnfältet för att skriva under.')]);
        }

        // The signed request, and whether it completed its envelope.
        $result = CurrentCompany::run($request->company_id, fn (): array => DB::transaction(function () use ($request, $validated, $name, $image, $ip, $userAgent): array {
            // The document first, then the request: the order every signing path takes (SignInSession, SendForSigning).
            $request->document(lock: true);
            $locked = $this->lockUsable($request);

            // The confirmation stored is the one the page showed; its MAC proves the page made it.
            if (! hash_equals(SigningPdfs::acceptanceMac($locked, (string) $validated['acceptance_text']), (string) $validated['acceptance_mac'])) {
                throw ValidationException::withMessages(['accept' => __('Sidan är för gammal. Ladda om den och signera igen.')]);
            }

            if (! hash_equals($locked->document_sha256, strtolower((string) $validated['document_sha256']))) {
                throw ValidationException::withMessages(['document_sha256' => __('Dokumentet har ändrats sedan sidan öppnades. Ladda om sidan och läs det igen.')]);
            }

            $bytes = Storage::disk(SigningRequest::DISK)->get($locked->document_path);

            if (! is_string($bytes) || ! hash_equals($locked->document_sha256, hash('sha256', $bytes))) {
                throw new SigningUnavailable(SigningUnavailable::GONE);
            }

            $this->checkDocument($locked);

            $locked->forceFill([
                'status' => SigningStatus::Signed,
                'acceptance_text' => (string) $validated['acceptance_text'],
                'signer_name' => $name,
                'signer_title' => blank($validated['title'] ?? null) ? null : trim((string) $validated['title']),
                'signature_method' => $validated['method'],
                'signature_image' => $image,
                'signed_at' => now(),
                'signer_ip' => $ip === null ? null : mb_substr($ip, 0, 45),
                'signer_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            ])->save();

            $this->chain->record($locked, SigningEventType::Signed, null, $locked->signer_ip, $locked->signer_user_agent, SigningChain::evidence($locked));

            $certificate = SigningPdfs::certificate($locked->refresh());
            $path = dirname($locked->document_path).'/'.pathinfo($locked->document_path, PATHINFO_FILENAME).'-signeringsbevis.pdf';
            Storage::disk(SigningRequest::DISK)->put($path, $certificate);
            $locked->forceFill(['signed_document_path' => $path, 'signed_document_sha256' => hash('sha256', $certificate)])->save();

            // The envelope: complete, or open the next step. The document is signed only when everyone has signed.
            $outcome = $this->envelopes->signed($locked);

            if ($outcome['completed']) {
                $locked->document()?->markSigned($locked->signed_at ?? CarbonImmutable::now(), $locked->document_sha256);
            }

            Audit::record('crm.signing_signed', $locked, ['signing_request_id' => $locked->id, 'document_type' => $locked->document_type, 'sales_document_id' => $locked->sales_document_id, 'agreement_id' => $locked->agreement_id, 'method' => $locked->signature_method], $locked->company_id);

            if ($outcome['completed'] && $locked->isQuote()) {
                event(new QuoteSigned($locked->company_id, $locked->id));
            }

            $this->mailOpenedStep($locked->company_id, $outcome['opened']);

            if ($outcome['completed'] && $outcome['several'] && $outcome['envelope'] instanceof SigningEnvelope) {
                SigningFollowUp::mailEnvelopeCopies($outcome['envelope']);
            }

            return [$locked, $outcome['completed']];
        }, self::ATTEMPTS));

        [$signed, $completed] = $result;

        // The signature is saved. The signer's copy goes whatever happens to the order, and with a sync queue a failing
        // follow-up must never turn the signer's success into an error page: it is reported and retried by hand.
        CurrentCompany::run($signed->company_id, fn () => SigningFollowUp::mailSignerCopy($signed));

        // An envelope still waiting for other signers has no follow-up yet.
        if (! $completed) {
            return $signed;
        }

        try {
            FollowUpSigning::dispatch($signed->company_id, $signed->id)->afterCommit();
        } catch (Throwable $exception) {
            Log::error('Signing follow-up failed', ['signing_request_id' => $signed->id, 'exception' => $exception::class]);

            // The sync queue has already called the job's failed(); any other driver failed to take the job at all.
            if (config('queue.connections.'.config('queue.default').'.driver') !== 'sync') {
                CurrentCompany::run($signed->company_id, fn () => app(SigningFollowUp::class)->failed($signed->company_id, $signed->id));
            }
        }

        return $signed;
    }

    /**
     * @throws SigningUnavailable
     * @throws ValidationException
     */
    public function decline(SigningRequest $request, ?string $reason): SigningRequest
    {
        Validator::make(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:1000']], [], ['reason' => __('anledning')])->validate();

        return CurrentCompany::run($request->company_id, fn (): SigningRequest => DB::transaction(function () use ($request, $reason): SigningRequest {
            $request->document(lock: true);
            $locked = $this->lockUsable($request);

            $locked->forceFill([
                'status' => SigningStatus::Declined,
                'declined_at' => now(),
                'decline_reason' => blank($reason) ? null : trim((string) $reason),
            ])->save();

            $this->chain->record($locked, SigningEventType::Declined, payload: SigningChain::evidence($locked));

            Audit::record('crm.signing_declined', $locked, ['signing_request_id' => $locked->id, 'document_type' => $locked->document_type, 'reason_given' => ! blank($reason)], $locked->company_id);

            // The envelope ends for everyone; those who already signed are told, without the reason.
            // TODO(legal-verify): ADR-046 gap 1 — whether earlier signers must be told when a later signer declines (default: yes, without the reason).
            $this->mailDeclinedToSigned($locked, $this->envelopes->declined($locked));
            app(SigningFollowUp::class)->declined($locked);

            return $locked;
        }, self::ATTEMPTS));
    }

    /** @throws SigningUnavailable */
    private function lockUsable(SigningRequest $request): SigningRequest
    {
        $locked = SigningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        $reason = $this->unavailable($locked);

        if ($reason !== null) {
            throw new SigningUnavailable($reason);
        }

        return $locked;
    }

    /**
     * The document must still be open and say what it said when it was
     * sent (a quote open, an agreement not signed yet). Otherwise the link
     * is revoked.
     *
     * @throws SigningUnavailable
     */
    private function checkDocument(SigningRequest $request): void
    {
        $document = $request->document(lock: true);

        if ($document instanceof Signable && $document->signingIsOpen() && hash_equals((string) $request->content_hash, $document->signingFingerprint())) {
            return;
        }

        // Revoked in its own statement before the exception: the caller's transaction rolls back, so it is written after it (see revokeStale).
        throw new SigningUnavailable(SigningUnavailable::CHANGED, $request->id);
    }

    /** A link whose document changed is revoked, so the page says so from now on. */
    public function revokeStale(SigningRequest $request): void
    {
        CurrentCompany::run($request->company_id, function () use ($request): void {
            DB::transaction(function () use ($request): void {
                // The document first, then the request and its envelope: the order every signing path takes.
                $request->document(lock: true);
                $locked = SigningRequest::query()->whereKey($request->id)->where('status', SigningStatus::Pending->value)->lockForUpdate()->first();

                if (! $locked instanceof SigningRequest) {
                    return;
                }

                $locked->forceFill(['status' => SigningStatus::Revoked, 'revoked_at' => CarbonImmutable::now()])->save();
                $this->chain->record($locked, SigningEventType::Revoked);
                Audit::record('crm.signing_revoked', $locked, ['signing_request_id' => $locked->id, 'reason' => 'DOCUMENT_CHANGED'], $locked->company_id);
                $this->envelopes->revoked($locked, null, 'DOCUMENT_CHANGED');
            }, self::ATTEMPTS);
        });
    }

    /**
     * The links of a step that just opened, mailed after commit.
     *
     * @param  list<array{request_id: int, email: string, url: string}>  $opened
     */
    private function mailOpenedStep(int $companyId, array $opened): void
    {
        if ($opened === []) {
            return;
        }

        $company = Company::query()->find($companyId);

        if (! $company instanceof Company || $company->isTestCompany()) {
            return;
        }

        foreach ($opened as $link) {
            Mail::to($link['email'])->queue((new SigningRequestMail($link['request_id'], $companyId, $link['url']))->afterCommit());
        }

        // Counted against the hour's quota, never refused: the signature that opened the step is already saved.
        MailQuota::count($company, 'documents', count($opened));
    }

    /** @param  list<int>  $signedIds */
    private function mailDeclinedToSigned(SigningRequest $declined, array $signedIds): void
    {
        if ($signedIds === [] || $declined->envelope_id === null) {
            return;
        }

        $company = Company::query()->find($declined->company_id);

        if (! $company instanceof Company || $company->isTestCompany()) {
            return;
        }

        foreach (SigningRequest::query()->whereKey($signedIds)->pluck('email') as $email) {
            Mail::to((string) $email)->queue((new SigningEnvelopeMail($declined->envelope_id, $company->id, SigningEnvelopeMail::DECLINED))->afterCommit());
        }
    }
}
