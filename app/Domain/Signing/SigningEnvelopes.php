<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningLinkToken;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The envelope rules (ADR-046 amendment 2026-11-18, gap 1), used inside the
 * signing transactions of SendForSigning, SignAtLink and SignInSession.
 *
 * Every signing request belongs to an envelope. A one-signer envelope
 * behaves exactly as a link always did (its token stays on the request, no
 * new audit entries). With several signers each signer has a step 1–10:
 * signers on the same step sign in parallel, and when every signer of the
 * open step has signed the next step opens and only then are its links
 * issued (signing_link_tokens) and mailed. When the last signer has signed
 * the envelope is COMPLETED and the follow-up runs once. A decline by
 * anyone ends the envelope (DECLINED) and revokes every open link;
 * revoking a link (the sender, a changed document, a new send) withdraws
 * the envelope (VOIDED) and revokes the others.
 *
 * Every path that changes an envelope takes the document's row lock first
 * (as signing always has), then the signer's request, then the envelope and
 * its other signer rows. So two signers on the same step sign one after
 * the other, and a send, a void and a signature never interleave.
 */
final class SigningEnvelopes
{
    public function __construct(private readonly SignableTypes $types) {}

    /**
     * A new envelope for a document; inside the sending transaction, with the document locked.
     *
     * @param  array{path: string, name: string, sha256: string}  $file
     */
    public function create(Signable $document, array $file, ?CarbonImmutable $deadline, ?int $userId): SigningEnvelope
    {
        return SigningEnvelope::create([
            ...$this->types->attributesFor($document),
            'title' => mb_substr($document->signingTitle(), 0, 255),
            'document_path' => $file['path'],
            'document_name' => $file['name'],
            'document_sha256' => $file['sha256'],
            'content_hash' => $document->signingFingerprint(),
            'deadline_at' => $deadline,
            'created_by' => $userId,
            'sent_at' => now(),
        ]);
    }

    /** Whether an envelope has more than one signer: the envelope rules apply only then. */
    public static function hasSeveralSigners(?string $envelopeId): bool
    {
        return $envelopeId !== null && SigningRequest::query()->where('envelope_id', $envelopeId)->count() > 1;
    }

    /**
     * Issues a link to a signer whose step is open: a new 256-bit token, its
     * hash in signing_link_tokens, a SENT event. The URL goes only to the
     * e-mail.
     */
    public function issue(SigningRequest $signer, ?int $userId): string
    {
        $token = SigningToken::generate();

        SigningLinkToken::create([
            'company_id' => $signer->company_id,
            'signing_request_id' => $signer->id,
            'token_hash' => $token['hash'],
            'issued_at' => now(),
            'reason' => SigningLinkToken::SENT,
        ]);

        SigningEvent::create(['signing_request_id' => $signer->id, 'event' => SigningEventType::Sent, 'user_id' => $userId]);

        return route('signing.show', ['token' => $token['token']]);
    }

    /**
     * After a signer has signed (the request locked and saved as SIGNED, in
     * the signing transaction): completes the envelope, or opens the next
     * step when the open one is done.
     *
     * @return array{completed: bool, several: bool, envelope: ?SigningEnvelope, opened: list<array{request_id: int, email: string, url: string}>}
     */
    public function signed(SigningRequest $signer): array
    {
        $envelope = $this->lock($signer);

        if (! $envelope instanceof SigningEnvelope) {
            return ['completed' => true, 'several' => false, 'envelope' => null, 'opened' => []];
        }

        $signers = $this->signers($envelope);
        $several = $signers->count() > 1;
        $pending = $signers->filter(fn (SigningRequest $row): bool => $row->status === SigningStatus::Pending);

        if ($pending->isEmpty()) {
            $envelope->forceFill(['status' => EnvelopeStatus::Completed, 'completed_at' => now()])->save();

            if ($several) {
                Audit::record('crm.signing_envelope_completed', $envelope, ['envelope_id' => $envelope->id, 'signers' => $signers->count()], $envelope->company_id);
            }

            return ['completed' => true, 'several' => $several, 'envelope' => $envelope, 'opened' => []];
        }

        if ($pending->contains(fn (SigningRequest $row): bool => $row->step <= $envelope->current_step)) {
            return ['completed' => false, 'several' => $several, 'envelope' => $envelope, 'opened' => []];
        }

        $next = (int) $pending->min('step');
        $envelope->forceFill(['current_step' => $next])->save();

        $opened = [];

        foreach ($pending->where('step', $next) as $row) {
            $opened[] = ['request_id' => $row->id, 'email' => $row->email, 'url' => $this->issue($row, null)];
        }

        Audit::record('crm.signing_envelope_step_opened', $envelope, ['envelope_id' => $envelope->id, 'step' => $next, 'signers' => count($opened)], $envelope->company_id);

        return ['completed' => false, 'several' => $several, 'envelope' => $envelope, 'opened' => $opened];
    }

    /**
     * After a signer has declined: the envelope ends (DECLINED) and every
     * other open link is revoked.
     *
     * @return list<int> the signers who had already signed, to be told (without the reason)
     */
    public function declined(SigningRequest $signer): array
    {
        $envelope = $this->lock($signer);
        $this->revokeTokens($signer->id);

        if (! $envelope instanceof SigningEnvelope || $envelope->status->isFinished()) {
            return [];
        }

        $envelope->forceFill(['status' => EnvelopeStatus::Declined, 'declined_at' => now()])->save();
        $signers = $this->signers($envelope);

        if ($signers->count() < 2) {
            return [];
        }

        $this->revokeOthers($signers, $signer->id, null, 'ENVELOPE_DECLINED');
        Audit::record('crm.signing_envelope_declined', $envelope, ['envelope_id' => $envelope->id, 'signers' => $signers->count()], $envelope->company_id);

        $signed = [];

        foreach ($signers as $row) {
            if ($row->status === SigningStatus::Signed) {
                $signed[] = $row->id;
            }
        }

        return $signed;
    }

    /**
     * After a link has been revoked (by the sender, a new send, a changed
     * document or an acceptance in the portal): the envelope is withdrawn
     * (VOIDED) and every other open link of it revoked.
     *
     * @return list<int> the other signers whose links were revoked
     */
    public function revoked(SigningRequest $signer, ?int $userId, string $reason): array
    {
        $envelope = $this->lock($signer);
        $this->revokeTokens($signer->id);

        if (! $envelope instanceof SigningEnvelope || $envelope->status->isFinished()) {
            return [];
        }

        $envelope->forceFill(['status' => EnvelopeStatus::Voided, 'voided_at' => now(), 'voided_by' => $userId])->save();
        $signers = $this->signers($envelope);

        if ($signers->count() < 2) {
            return [];
        }

        $others = $this->revokeOthers($signers, $signer->id, $userId, $reason);
        Audit::record('crm.signing_envelope_voided', $envelope, ['envelope_id' => $envelope->id, 'signers' => $signers->count(), 'reason' => $reason], $envelope->company_id);

        return $others;
    }

    /** The envelope behind a request, locked for update, or null for a request without one. */
    public function lock(SigningRequest $signer): ?SigningEnvelope
    {
        return $signer->envelope_id === null ? null : SigningEnvelope::query()->whereKey($signer->envelope_id)->lockForUpdate()->first();
    }

    /** @return Collection<int, SigningRequest> */
    private function signers(SigningEnvelope $envelope): Collection
    {
        return SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('step')->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @param  Collection<int, SigningRequest>  $signers
     * @return list<int>
     */
    private function revokeOthers(Collection $signers, int $except, ?int $userId, string $reason): array
    {
        $revoked = [];

        foreach ($signers as $row) {
            if ($row->id === $except || $row->status !== SigningStatus::Pending) {
                continue;
            }

            $row->forceFill(['status' => SigningStatus::Revoked, 'revoked_at' => now()])->save();
            SigningEvent::create(['signing_request_id' => $row->id, 'event' => SigningEventType::Revoked, 'user_id' => $userId]);
            Audit::record('crm.signing_revoked', $row, ['signing_request_id' => $row->id, 'envelope_id' => $row->envelope_id, 'reason' => $reason], $row->company_id);
            $this->revokeTokens($row->id);
            $revoked[] = $row->id;
        }

        return $revoked;
    }

    private function revokeTokens(int $requestId): void
    {
        SigningLinkToken::query()->where('signing_request_id', $requestId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
