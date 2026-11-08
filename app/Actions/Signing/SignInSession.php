<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Signable;
use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SignedDocuments;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningFollowUp;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningToken;
use App\Events\QuoteSigned;
use App\Jobs\FollowUpSigning;
use App\Models\SalesDocument;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Accepting or declining a document by someone who is signed in rather than
 * holding a link (ADR-075 §7): a customer portal contact, identified by the
 * portal session (an e-mail code). The same evidence as at a link
 * (ADR-046): the exact PDF rendered at that moment and its SHA-256, the
 * document's fingerprint (it must equal the one the page showed), the typed
 * name, the confirmation text, time, IP address and browser, and a signing
 * certificate; the same follow-up (a quote becomes an order, CRM's steps).
 * It is kept as a signing request with channel PORTAL.
 *
 * Under the same row locks as SignAtLink: an open link for the document is
 * revoked when it is accepted here, and a document signed at a link can no
 * longer be accepted here.
 *
 * TODO(legal-verify): L7 — the evidential value of acceptance in a signed-in
 * session compared with the link (eIDAS art. 25(1), freedom of form).
 */
final class SignInSession
{
    public const string PORTAL = 'PORTAL';

    public function __construct(private readonly SignableTypes $types) {}

    /**
     * @param  array{email: string, name: string, contact_id: ?int, ip: ?string, user_agent: ?string}  $signer
     *
     * @throws ValidationException
     */
    public function accept(Signable $document, array $signer, string $acceptanceText, string $fingerprintShown): SigningRequest
    {
        $name = trim($signer['name']);
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u']], [], ['name' => __('namn')])->validate();

        $signed = CurrentCompany::run($document->signingCompanyId(), fn (): SigningRequest => DB::transaction(function () use ($document, $signer, $name, $acceptanceText, $fingerprintShown): SigningRequest {
            $locked = $this->lockOpen($document, $fingerprintShown);
            $request = $this->record($locked, $signer);

            $request->forceFill([
                'status' => SigningStatus::Signed,
                'acceptance_text' => mb_substr($acceptanceText, 0, 500),
                'signer_name' => $name,
                'signature_method' => SigningRequest::TYPED,
                'signed_at' => now(),
            ])->save();

            SigningEvent::create(['signing_request_id' => $request->id, 'event' => SigningEventType::Signed, 'ip' => $request->signer_ip, 'user_agent' => $request->signer_user_agent]);

            $certificate = SigningPdfs::certificate($request->refresh());
            $path = dirname($request->document_path).'/'.pathinfo($request->document_path, PATHINFO_FILENAME).'-signeringsbevis.pdf';
            Storage::disk(SigningRequest::DISK)->put($path, $certificate);
            $request->forceFill(['signed_document_path' => $path, 'signed_document_sha256' => hash('sha256', $certificate)])->save();

            $locked->markSigned($request->signed_at ?? CarbonImmutable::now(), $request->document_sha256);

            Audit::record('crm.signing_signed', $request, ['signing_request_id' => $request->id, 'document_type' => $request->document_type, 'sales_document_id' => $request->sales_document_id, 'channel' => self::PORTAL, 'method' => SigningRequest::TYPED], $request->company_id);

            if ($request->isQuote()) {
                event(new QuoteSigned($request->company_id, $request->id));
            }

            return $request;
        }));

        CurrentCompany::run($signed->company_id, fn () => SigningFollowUp::mailSignerCopy($signed));

        try {
            FollowUpSigning::dispatch($signed->company_id, $signed->id)->afterCommit();
        } catch (Throwable $exception) {
            Log::error('Signing follow-up failed', ['signing_request_id' => $signed->id, 'exception' => $exception::class]);
        }

        return $signed;
    }

    /**
     * @param  array{email: string, name: string, contact_id: ?int, ip: ?string, user_agent: ?string}  $signer
     *
     * @throws ValidationException
     */
    public function decline(Signable $document, array $signer, ?string $reason, string $fingerprintShown): SigningRequest
    {
        Validator::make(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:1000']], [], ['reason' => __('anledning')])->validate();

        return CurrentCompany::run($document->signingCompanyId(), fn (): SigningRequest => DB::transaction(function () use ($document, $signer, $reason, $fingerprintShown): SigningRequest {
            $locked = $this->lockOpen($document, $fingerprintShown);
            // Declining keeps no IP address or browser (ADR-046, data minimisation).
            $request = $this->record($locked, [...$signer, 'ip' => null, 'user_agent' => null]);

            $request->forceFill(['status' => SigningStatus::Declined, 'declined_at' => now(), 'decline_reason' => blank($reason) ? null : trim((string) $reason)])->save();
            SigningEvent::create(['signing_request_id' => $request->id, 'event' => SigningEventType::Declined]);

            Audit::record('crm.signing_declined', $request, ['signing_request_id' => $request->id, 'document_type' => $request->document_type, 'channel' => self::PORTAL, 'reason_given' => ! blank($reason)], $request->company_id);
            app(SigningFollowUp::class)->declined($request);

            return $request;
        }));
    }

    /** Revokes any open link for the document, then locks it and checks it is still open and unchanged. */
    private function lockOpen(Signable $document, string $fingerprintShown): Signable
    {
        $open = SigningRequest::query()
            ->where('document_type', $document->signingType())
            ->where($this->types->column($document->signingType()), $document->signingKey())
            ->where('status', SigningStatus::Pending->value)
            ->lockForUpdate()
            ->get();

        foreach ($open as $link) {
            $link->forceFill(['status' => SigningStatus::Revoked, 'revoked_at' => now()])->save();
            SigningEvent::create(['signing_request_id' => $link->id, 'event' => SigningEventType::Revoked]);
            Audit::record('crm.signing_revoked', $link, ['signing_request_id' => $link->id, 'reason' => 'ACCEPTED_IN_PORTAL'], $link->company_id);
        }

        $locked = $this->types->documentFor(new SigningRequest($this->types->attributesFor($document)), lock: true);

        if (! $locked instanceof Signable || ! $locked->signingIsOpen() || ($locked instanceof SalesDocument && SignedDocuments::isQuoteSigned($locked->id))) {
            throw ValidationException::withMessages(['document' => __('Dokumentet går inte längre att godkänna. Det kan redan vara godkänt, avböjt eller stängt.')]);
        }

        if (! hash_equals($locked->signingFingerprint(), $fingerprintShown)) {
            throw ValidationException::withMessages(['document' => __('Dokumentet har ändrats sedan sidan öppnades. Ladda om sidan och läs det igen.')]);
        }

        return $locked;
    }

    /** @param  array{email: string, name: string, contact_id: ?int, ip: ?string, user_agent: ?string}  $signer */
    private function record(Signable $document, array $signer): SigningRequest
    {
        $pdf = $document->signingDocument();
        $path = "crm-signing/{$document->signingCompanyId()}/".Str::uuid()->toString().'.pdf';
        Storage::disk(SigningRequest::DISK)->put($path, $pdf['bytes']);

        $request = SigningRequest::create([
            ...$this->types->attributesFor($document),
            // No link: an unguessable token is stored only to fill the column; nobody ever holds it.
            'token_hash' => SigningToken::generate()['hash'],
            'email' => mb_strtolower($signer['email']),
            'expires_at' => now(),
            'document_path' => $path,
            'document_name' => $pdf['name'],
            'document_sha256' => hash('sha256', $pdf['bytes']),
            'content_hash' => $document->signingFingerprint(),
            'create_invoice' => false,
            'channel' => self::PORTAL,
            'portal_contact_id' => $signer['contact_id'],
        ]);

        $request->forceFill([
            'signer_ip' => $signer['ip'] === null ? null : mb_substr($signer['ip'], 0, 45),
            'signer_user_agent' => $signer['user_agent'] === null ? null : mb_substr($signer['user_agent'], 0, 500),
        ])->save();

        return $request;
    }
}
