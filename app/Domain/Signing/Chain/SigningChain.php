<?php

declare(strict_types=1);

namespace App\Domain\Signing\Chain;

use App\Domain\Signing\SigningEventType;
use App\Events\SigningEnvelopeChanged;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use stdClass;

/**
 * The hash chain of a signing envelope (ADR-046 amendment 2026-11-18, gap
 * 4, package S2). Every signing event is written here, inside the signing
 * transaction that causes it:
 *
 * - The envelope's row is locked, so two events of one envelope get the
 *   next sequence numbers one after the other. Every signing path already
 *   holds the document and the signer row before it records an event, so
 *   the envelope comes last in the lock order (SigningEnvelopes).
 * - The event gets sequence n + 1, previous_hash = the hash of event n (or
 *   the document's SHA-256 for the first), and hash = sha256(previous_hash
 *   || canonical_json(event)) (EventHash). A trigger checks every link again.
 * - An envelope that existed before the chain starts it with CHAIN_STARTED,
 *   whose payload holds the count and a digest of its earlier, unchained
 *   events per signer (priorPayload), so those are covered too.
 *
 * When an envelope finishes (completed, declined, withdrawn), close() keeps
 * the chain's last hash on the envelope and writes it to the audit log,
 * which is itself append-only: deleting a finished envelope is then visible
 * there. signing:verify recomputes everything (ChainVerifier).
 */
final class SigningChain
{
    /** The audit action that carries a finished envelope's head, per final status. */
    public const array HEAD_ACTIONS = [
        'COMPLETED' => 'signing.completed',
        'DECLINED' => 'signing.declined',
        'VOIDED' => 'signing.voided',
    ];

    /** The head written by the S2 backfill, for an envelope that finished before the chain. */
    public const string BACKFILLED = 'signing.chain_backfilled';

    /** A pruned signer of an envelope that stays: the hashes of its events, so the chain still links. */
    public const string PRUNED = 'signing.events_pruned';

    /**
     * Records one event of a signer, chained to the envelope's events. A
     * request without an envelope (only rows written before S1) records a
     * plain event, as before.
     *
     * @param  ?array<string, mixed>  $payload  ids, enums, counts and hashes only
     */
    public function record(SigningRequest $request, SigningEventType $type, ?int $userId = null, ?string $ip = null, ?string $userAgent = null, ?array $payload = null): SigningEvent
    {
        if ($request->envelope_id === null) {
            return SigningEvent::create([
                'signing_request_id' => $request->id,
                'event' => $type,
                'user_id' => $userId,
                'ip' => $ip,
                'user_agent' => $userAgent,
            ]);
        }

        $envelope = $this->lock($request->envelope_id);

        if ($envelope->next_sequence === 1) {
            $this->start($envelope);
        }

        $event = $this->append($envelope, $request->id, $type, $userId, $ip, $userAgent, $payload);

        // Announced once per step, after the transaction commits (ADR-046 S19): the webhooks read the saved state.
        $change = match ($type) {
            SigningEventType::Signed => SigningEnvelopeChanged::RECIPIENT_SIGNED,
            SigningEventType::Completed => SigningEnvelopeChanged::COMPLETED,
            SigningEventType::Declined => SigningEnvelopeChanged::DECLINED,
            SigningEventType::Expired => SigningEnvelopeChanged::EXPIRED,
            default => null,
        };

        if ($change !== null) {
            event(new SigningEnvelopeChanged($envelope->company_id, $envelope->id, $request->id, $change));
        }

        return $event;
    }

    /**
     * The envelope has finished (its status saved, under its row lock): the
     * final event, when there is one, then the head on the envelope and in
     * the audit log. Once only; the head never changes (trigger).
     *
     * @param  ?array<string, mixed>  $payload
     */
    public function close(SigningEnvelope $envelope, SigningRequest $by, ?SigningEventType $final = null, ?int $userId = null, ?array $payload = null): void
    {
        if ($envelope->chain_head !== null) {
            return;
        }

        if ($final !== null) {
            $this->record($by, $final, $userId, payload: $payload);
        }

        // The counter the insert trigger keeps: the last link, even when a pruned signer's events are gone.
        $counter = $this->lock($envelope->id);

        if ($counter->last_hash === null) {
            return;
        }

        $sequence = $counter->next_sequence - 1;
        $envelope->forceFill(['chain_head' => $counter->last_hash, 'chain_sequence' => $sequence])->save();

        Audit::record(self::HEAD_ACTIONS[$envelope->status->value] ?? 'signing.closed', $envelope, [
            'envelope_id' => $envelope->id,
            'status' => $envelope->status->value,
            'chain_sequence' => $sequence,
            'chain_head' => $counter->last_hash,
        ], $envelope->company_id);
    }

    /**
     * The digest of a signer's evidence, for the payload of SIGNED and DECLINED.
     *
     * @return array{evidence: string}
     */
    public static function evidence(SigningRequest $request): array
    {
        return ['evidence' => EventHash::evidenceOf($request)];
    }

    /**
     * The payload of CHAIN_STARTED: the envelope's earlier, unchained events,
     * counted and digested per signer row (so a signer pruned later can be
     * left out without breaking the others).
     *
     * @param  iterable<SigningEvent|stdClass>  $events  with id, signing_request_id, event, user_id, ip, user_agent, created_at
     * @return array{events: int, signers: list<array{signing_request_id: int, events: int, digest: string}>}
     */
    public static function priorPayload(iterable $events): array
    {
        $perRequest = [];

        foreach ($events as $event) {
            $perRequest[(int) $event->signing_request_id][] = $event;
        }

        ksort($perRequest);
        $signers = [];
        $total = 0;

        foreach ($perRequest as $requestId => $rows) {
            $signers[] = ['signing_request_id' => $requestId, 'events' => count($rows), 'digest' => self::priorDigest($rows)];
            $total += count($rows);
        }

        return ['events' => $total, 'signers' => $signers];
    }

    /** @param  list<SigningEvent|stdClass>  $events */
    public static function priorDigest(array $events): string
    {
        usort($events, fn (SigningEvent|stdClass $a, SigningEvent|stdClass $b): int => (int) $a->id <=> (int) $b->id);

        return hash('sha256', CanonicalJson::encode(array_map(fn (SigningEvent|stdClass $event): array => [
            'created_at' => CanonicalJson::time(self::time($event->created_at)),
            'event' => $event->event instanceof SigningEventType ? $event->event->value : (string) $event->event,
            'id' => (int) $event->id,
            'ip' => $event->ip,
            'signing_request_id' => (int) $event->signing_request_id,
            'user_agent' => $event->user_agent,
            'user_id' => $event->user_id === null ? null : (int) $event->user_id,
        ], $events)));
    }

    /** The envelope's row, locked, with its chain counter (kept by the insert trigger). */
    private function lock(string $envelopeId): SigningEnvelope
    {
        return SigningEnvelope::query()->whereKey($envelopeId)->lockForUpdate()->firstOrFail(['id', 'company_id', 'document_sha256', 'next_sequence', 'last_hash']);
    }

    /**
     * The first link of an envelope written before the chain: CHAIN_STARTED
     * naming its earlier events. An envelope without earlier events starts
     * with its own first event instead.
     */
    private function start(SigningEnvelope $envelope): void
    {
        $requests = SigningRequest::query()->where('envelope_id', $envelope->id)->select('id');
        $prior = SigningEvent::query()->whereNull('envelope_id')->whereIn('signing_request_id', $requests)->orderBy('id')->get();

        if ($prior->isEmpty()) {
            return;
        }

        $this->append($envelope, self::anchor($envelope->id), SigningEventType::ChainStarted, null, null, null, self::priorPayload($prior));
    }

    /** The signer row CHAIN_STARTED is recorded on: a signed one when there is one (never pruned), else the first. */
    public static function anchor(string $envelopeId): int
    {
        return (int) SigningRequest::query()->where('envelope_id', $envelopeId)->orderByRaw("CASE WHEN status = 'SIGNED' THEN 0 ELSE 1 END")->orderBy('id')->value('id');
    }

    /** @param  ?array<string, mixed>  $payload */
    private function append(SigningEnvelope $envelope, int $requestId, SigningEventType $type, ?int $userId, ?string $ip, ?string $userAgent, ?array $payload): SigningEvent
    {
        $event = new SigningEvent([
            'company_id' => $envelope->company_id,
            'signing_request_id' => $requestId,
            'event' => $type,
            'user_id' => $userId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'envelope_id' => $envelope->id,
            'sequence' => $envelope->next_sequence,
            'previous_hash' => $envelope->last_hash ?? $envelope->document_sha256,
            'payload' => $payload === [] ? null : $payload,
            // The column keeps whole seconds (timestamptz(0), which rounds): the time is cut to the second first, so the hash is of what is stored.
            'created_at' => CarbonImmutable::now()->startOfSecond(),
        ]);

        $event->hash = EventHash::ofEvent($event);
        $event->save();

        // The trigger moved the counter in the row; this copy follows it.
        $envelope->forceFill(['next_sequence' => $event->sequence + 1, 'last_hash' => $event->hash])->syncOriginal();

        return $event;
    }

    private static function time(mixed $value): DateTimeInterface|string
    {
        return $value instanceof DateTimeInterface ? $value : (string) $value;
    }
}
