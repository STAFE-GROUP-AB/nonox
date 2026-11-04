<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\SalesDocument;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\QuoteFingerprint;
use App\Modules\Crm\Domain\SignatureImage;
use App\Modules\Crm\Domain\SigningEventType;
use App\Modules\Crm\Domain\SigningStatus;
use App\Modules\Crm\Domain\SigningToken;
use App\Modules\Crm\Events\QuoteSigned;
use App\Modules\Crm\Jobs\FollowUpSigning;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\SigningEvent;
use App\Modules\Crm\Models\SigningRequest;
use App\Modules\Crm\Support\SigningFollowUp;
use App\Modules\Crm\Support\SigningPdfs;
use App\Modules\Crm\Support\SigningUnavailable;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What the signer does at the public link (ADR-046): open it, sign it or
 * decline it. No account: the unguessable token is the only key, and it is
 * looked up by its SHA-256 alone, so the page can never reach another
 * company's data. Every step locks the request row, so a double submit or
 * two tabs sign once; a link is used once.
 *
 * Signing binds to the exact PDF shown: the form carries the SHA-256 the
 * page showed, and it must equal the one stored when the link was sent and
 * the stored file's own hash. A quote that changed after it was sent cannot
 * be signed (QuoteFingerprint): the link is revoked instead.
 */
final class SignAtLink
{
    /** The request behind a token, in any company, or null. */
    public function find(string $token): ?SigningRequest
    {
        if (! SigningToken::isWellFormed($token)) {
            return null;
        }

        $hash = SigningToken::hash($token);

        // The token is the only key on this public page: the lookup crosses companies by its hash, and nothing else is matched.
        $request = CompanyScope::bypass(fn (): ?SigningRequest => SigningRequest::query()->where('token_hash', $hash)->first());

        return $request instanceof SigningRequest && hash_equals($request->token_hash, $hash) ? $request : null;
    }

    /**
     * Why the link cannot be used, or null when it can: the stored status,
     * expiry, the module, an archived company.
     */
    public function unavailable(SigningRequest $request): ?string
    {
        $company = Company::query()->find($request->company_id);

        if (! $company instanceof Company || $company->isArchived() || ! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company)) {
            return SigningUnavailable::GONE;
        }

        return match ($request->effectiveStatus()) {
            SigningStatus::Pending => null,
            SigningStatus::Signed => SigningUnavailable::SIGNED,
            SigningStatus::Declined => SigningUnavailable::DECLINED,
            SigningStatus::Revoked => SigningUnavailable::REVOKED,
            SigningStatus::Expired => SigningUnavailable::EXPIRED,
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
                $updated = SigningRequest::query()->whereKey($request->id)->whereNull('opened_at')->where('status', SigningStatus::Pending->value)->update(['opened_at' => now()]);

                if ($updated === 1) {
                    SigningEvent::create(['signing_request_id' => $request->id, 'event' => SigningEventType::Opened]);
                }
            });
        });
    }

    /** An expired link that is visited gets its EXPIRED event once. */
    public function noteExpired(SigningRequest $request): void
    {
        CurrentCompany::run($request->company_id, function () use ($request): void {
            DB::transaction(function () use ($request): void {
                $locked = SigningRequest::query()->whereKey($request->id)->lockForUpdate()->first();

                if (! $locked instanceof SigningRequest || $locked->effectiveStatus() !== SigningStatus::Expired) {
                    return;
                }

                if (! SigningEvent::query()->where('signing_request_id', $locked->id)->where('event', SigningEventType::Expired->value)->exists()) {
                    SigningEvent::create(['signing_request_id' => $locked->id, 'event' => SigningEventType::Expired]);
                }
            });
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

        $signed = CurrentCompany::run($request->company_id, fn (): SigningRequest => DB::transaction(function () use ($request, $validated, $name, $image, $ip, $userAgent): SigningRequest {
            $locked = $this->lockUsable($request);

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
                'acceptance_text' => SigningPdfs::acceptance($locked),
                'signer_name' => $name,
                'signer_title' => blank($validated['title'] ?? null) ? null : trim((string) $validated['title']),
                'signature_method' => $validated['method'],
                'signature_image' => $image,
                'signed_at' => now(),
                'signer_ip' => $ip === null ? null : mb_substr($ip, 0, 45),
                'signer_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            ])->save();

            SigningEvent::create([
                'signing_request_id' => $locked->id,
                'event' => SigningEventType::Signed,
                'ip' => $locked->signer_ip,
                'user_agent' => $locked->signer_user_agent,
            ]);

            $certificate = SigningPdfs::certificate($locked->refresh());
            $path = dirname($locked->document_path).'/'.pathinfo($locked->document_path, PATHINFO_FILENAME).'-signeringsbevis.pdf';
            Storage::disk(SigningRequest::DISK)->put($path, $certificate);
            $locked->forceFill(['signed_document_path' => $path, 'signed_document_sha256' => hash('sha256', $certificate)])->save();

            if ($locked->agreement_id !== null) {
                Agreement::query()->whereKey($locked->agreement_id)->update(['signed_at' => $locked->signed_at, 'document_sha256' => $locked->document_sha256]);
            }

            Audit::record('crm.signing_signed', $locked, ['signing_request_id' => $locked->id, 'document_type' => $locked->document_type, 'sales_document_id' => $locked->sales_document_id, 'agreement_id' => $locked->agreement_id, 'method' => $locked->signature_method], $locked->company_id);

            if ($locked->isQuote()) {
                event(new QuoteSigned($locked->company_id, $locked->id));
            }

            return $locked;
        }));

        // The signature is saved. The signer's copy goes whatever happens to the order, and with a sync queue a failing
        // follow-up must never turn the signer's success into an error page: it is reported and retried by hand.
        CurrentCompany::run($signed->company_id, fn () => SigningFollowUp::mailSignerCopy($signed));

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
            $locked = $this->lockUsable($request);

            $locked->forceFill([
                'status' => SigningStatus::Declined,
                'declined_at' => now(),
                'decline_reason' => blank($reason) ? null : trim((string) $reason),
            ])->save();

            SigningEvent::create([
                'signing_request_id' => $locked->id,
                'event' => SigningEventType::Declined,
            ]);

            Audit::record('crm.signing_declined', $locked, ['signing_request_id' => $locked->id, 'document_type' => $locked->document_type, 'reason_given' => ! blank($reason)], $locked->company_id);
            app(SigningFollowUp::class)->declined($locked);

            return $locked;
        }));
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
     * A quote must still be open and say what it said when it was sent;
     * an agreement must not be signed already. Otherwise the link is revoked.
     *
     * @throws SigningUnavailable
     */
    private function checkDocument(SigningRequest $request): void
    {
        $stale = false;

        if ($request->isQuote()) {
            $quote = SalesDocument::query()->whereKey($request->sales_document_id)->lockForUpdate()->first();
            $stale = ! $quote instanceof SalesDocument || ! $quote->isOpen() || ! hash_equals((string) $request->content_hash, QuoteFingerprint::for($quote));
        } else {
            $agreement = Agreement::query()->whereKey($request->agreement_id)->lockForUpdate()->first();
            $stale = ! $agreement instanceof Agreement || $agreement->signed_at !== null || ! hash_equals((string) $request->content_hash, QuoteFingerprint::forAgreement($agreement));
        }

        if (! $stale) {
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
                $locked = SigningRequest::query()->whereKey($request->id)->where('status', SigningStatus::Pending->value)->lockForUpdate()->first();

                if (! $locked instanceof SigningRequest) {
                    return;
                }

                $locked->forceFill(['status' => SigningStatus::Revoked, 'revoked_at' => CarbonImmutable::now()])->save();
                SigningEvent::create(['signing_request_id' => $locked->id, 'event' => SigningEventType::Revoked]);
                Audit::record('crm.signing_revoked', $locked, ['signing_request_id' => $locked->id, 'reason' => 'DOCUMENT_CHANGED'], $locked->company_id);
            });
        });
    }
}
