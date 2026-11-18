<?php

declare(strict_types=1);

namespace App\Domain\Signing\Chain;

use App\Domain\Signing\SigningEventType;
use App\Models\SigningEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Starts the chain of every envelope that has none (ADR-046 amendment, gap
 * 4, package S2), run once by the S2 migration. Inserts rows only, outside
 * any company (the migration knows none) and through the query builder, so
 * an archived company's envelopes get their chain too:
 *
 * - the ORIGINAL row in signing_document_versions (its size is not known
 *   without reading the file, so it stays empty);
 * - a CHAIN_STARTED event whose previous_hash is the document's SHA-256 and
 *   whose payload counts and digests the envelope's earlier events per
 *   signer (SigningChain::priorPayload);
 * - for a finished envelope, its head (chain_head, chain_sequence, set once
 *   from null) and an audit entry signing.chain_backfilled with ids, the
 *   status and the head.
 *
 * Nothing that exists is changed. Idempotent: an envelope with a chain or an
 * ORIGINAL row is left alone. An envelope whose chain counter has moved
 * (next_sequence > 1) has a chain; the insert trigger keeps the counter.
 */
final class SigningChainBackfill
{
    /** @return array{envelopes: int, versions: int} */
    public function run(): array
    {
        $versions = DB::affectingStatement(<<<'SQL'
            INSERT INTO signing_document_versions (company_id, envelope_id, kind, signing_request_id, path, sha256, bytes, created_at)
            SELECT e.company_id, e.id, 'ORIGINAL', NULL, e.document_path, e.document_sha256, NULL, coalesce(e.sent_at, e.created_at, now())
            FROM signing_envelopes e
            WHERE NOT EXISTS (SELECT 1 FROM signing_document_versions v WHERE v.envelope_id = e.id AND v.kind = 'ORIGINAL')
            SQL);

        $morph = (new SigningEnvelope)->getMorphClass();
        $started = 0;

        DB::table('signing_envelopes')
            ->where('next_sequence', 1)
            ->orderBy('id')
            ->select(['id', 'company_id', 'status', 'document_sha256', 'chain_head'])
            ->lazyById(200, 'id')
            ->each(function (stdClass $envelope) use ($morph, &$started): void {
                if ($this->start($envelope, $morph)) {
                    $started++;
                }
            });

        return ['envelopes' => $started, 'versions' => $versions];
    }

    private function start(stdClass $envelope, string $morph): bool
    {
        $requests = DB::table('crm_signing_requests')->where('envelope_id', $envelope->id)->orderBy('id')->get(['id', 'status']);

        if ($requests->isEmpty()) {
            return false;
        }

        $anchor = (int) ($requests->firstWhere('status', 'SIGNED') ?? $requests->first())->id;
        $prior = DB::table('crm_signing_events')
            ->whereNull('envelope_id')
            ->whereIn('signing_request_id', $requests->pluck('id')->all())
            ->orderBy('id')
            ->get(['id', 'signing_request_id', 'event', 'user_id', 'ip', 'user_agent', 'created_at']);

        $payload = SigningChain::priorPayload($prior);
        // Whole seconds, as the column keeps them (timestamptz(0)).
        $now = CarbonImmutable::now()->startOfSecond();
        $fields = EventHash::fields(
            companyId: (int) $envelope->company_id,
            envelopeId: (string) $envelope->id,
            sequence: 1,
            requestId: $anchor,
            event: SigningEventType::ChainStarted,
            userId: null,
            ip: null,
            userAgent: null,
            createdAt: $now,
            payload: $payload,
        );
        $hash = EventHash::of($fields, (string) $envelope->document_sha256);

        DB::table('crm_signing_events')->insert([
            'company_id' => (int) $envelope->company_id,
            'signing_request_id' => $anchor,
            'event' => SigningEventType::ChainStarted->value,
            'envelope_id' => $envelope->id,
            'sequence' => 1,
            'previous_hash' => $envelope->document_sha256,
            'hash' => $hash,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now->format('Y-m-d H:i:sP'),
        ]);

        if (! in_array($envelope->status, ['COMPLETED', 'DECLINED', 'VOIDED'], true) || $envelope->chain_head !== null) {
            return true;
        }

        DB::table('signing_envelopes')->where('id', $envelope->id)->whereNull('chain_head')->update(['chain_head' => $hash, 'chain_sequence' => 1]);

        DB::table('audit_logs')->insert([
            'company_id' => (int) $envelope->company_id,
            'action' => SigningChain::BACKFILLED,
            'subject_type' => $morph,
            'subject_id' => (string) $envelope->id,
            'payload' => json_encode([
                'envelope_id' => $envelope->id,
                'status' => $envelope->status,
                'chain_sequence' => 1,
                'chain_head' => $hash,
                'prior_events' => $payload['events'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return true;
    }
}
