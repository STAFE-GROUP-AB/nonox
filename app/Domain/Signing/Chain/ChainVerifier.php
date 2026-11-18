<?php

declare(strict_types=1);

namespace App\Domain\Signing\Chain;

use App\Domain\Signing\SigningEventType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Recomputes a company's signing chains (`signing:verify`, ADR-046
 * amendment, gap 4), the signing counterpart of LedgerVerifier. For every
 * envelope, up to the counter the insert trigger keeps (next_sequence):
 *
 * - every chained event hashes to what is stored, follows the sequence
 *   without gaps and links to the event before it, the first to the
 *   document's SHA-256; a missing link is accepted only where the audit log
 *   names it as a pruned signer's link (signing.events_pruned);
 * - the counter's last hash is the chain's last link, and no event lies
 *   beyond a finished envelope's head;
 * - the SIGNED and DECLINED events still match the signer row's evidence;
 * - CHAIN_STARTED still matches the earlier, unchained events it names, and
 *   an envelope that started its chain without it has no unchained events;
 * - a finished envelope's head is the hash at its sequence, and the audit
 *   log holds that head; an envelope with a head or a moved counter but no
 *   chain left is reported, also when it has no signer rows;
 * - every file in signing_document_versions of an envelope that still has
 *   signers, and every signer's Signeringsbevis, still has its SHA-256.
 *
 * A fixed number of queries per company (the signer rows with the drawn
 * signature in chunks), whatever the number of envelopes; the files are read
 * one by one. Problems are codes with ids, never values, since the result
 * goes to the audit log.
 */
final class ChainVerifier
{
    /** The problem codes. */
    public const array CODES = ['NO_CHAIN', 'GAP', 'LINK', 'HASH', 'COUNTER', 'AFTER_HEAD', 'EVIDENCE', 'PRIOR', 'UNCHAINED', 'HEAD', 'AUDIT', 'FILE_MISSING', 'FILE_CHANGED'];

    /** The signer row columns the checks read; the drawn signature only in the evidence pass. */
    private const array REQUEST_COLUMNS = ['id', 'envelope_id', 'status', 'signed_document_path', 'signed_document_sha256'];

    /** @var list<array<string, int|string|null>> */
    private array $problems = [];

    /**
     * @return array{ok: bool, envelopes: int, events: int, files: int, problems: list<array<string, int|string|null>>}
     */
    public function verify(Company $company): array
    {
        $this->problems = [];

        return CurrentCompany::run($company, function () use ($company): array {
            $envelopes = SigningEnvelope::query()->orderBy('id')->get(['id', 'company_id', 'status', 'document_sha256', 'chain_head', 'chain_sequence', 'next_sequence', 'last_hash'])->keyBy('id');
            $requests = SigningRequest::query()->whereNotNull('envelope_id')->get(self::REQUEST_COLUMNS);
            $signers = [];

            foreach ($requests as $request) {
                $signers[(string) $request->envelope_id][] = $request->id;
            }

            $legacy = [];

            foreach (SigningEvent::query()->whereNull('envelope_id')->whereIn('signing_request_id', SigningRequest::query()->whereNotNull('envelope_id')->select('id'))->orderBy('id')->get() as $old) {
                $legacy[$old->signing_request_id][] = $old;
            }

            $chains = [];
            $events = 0;

            foreach (SigningEvent::query()->whereNotNull('envelope_id')->orderBy('envelope_id')->orderBy('sequence')->cursor() as $event) {
                $events++;
                $chains[(string) $event->envelope_id][(int) $event->sequence] = $event;
            }

            [$pruned, $prunedRequests] = $this->pruned($company);
            $heads = $this->heads($company);
            $evidence = [];

            foreach ($envelopes as $envelope) {
                $this->checkChain($envelope, $chains[$envelope->id] ?? [], $pruned[$envelope->id] ?? [], $signers[$envelope->id] ?? [], $legacy, $prunedRequests, $heads, $evidence);
            }

            foreach (array_diff_key($chains, $envelopes->all()) as $envelopeId => $ignored) {
                // Events of an envelope that is gone: they go with their signer rows, so none can be left.
                $this->problems[] = ['envelope_id' => (string) $envelopeId, 'code' => 'NO_CHAIN'];
            }

            $this->checkEvidence($evidence);
            $files = $this->checkFiles($requests, $signers);

            return ['ok' => $this->problems === [], 'envelopes' => $envelopes->count(), 'events' => $events, 'files' => $files, 'problems' => $this->problems];
        });
    }

    /**
     * @param  array<int, SigningEvent>  $events  sequence => event
     * @param  array<int, array{hash: string, event: string}>  $pruned  sequence => a pruned signer's link
     * @param  list<int>  $signerIds
     * @param  array<int, list<SigningEvent>>  $legacy
     * @param  array<int, true>  $prunedRequests
     * @param  array<string, list<string>>  $heads
     * @param  array<int, array{envelope_id: string, sequence: int, digest: string}>  $evidence
     */
    private function checkChain(SigningEnvelope $envelope, array $events, array $pruned, array $signerIds, array $legacy, array $prunedRequests, array $heads, array &$evidence): void
    {
        $counted = $envelope->next_sequence - 1;
        $top = max($counted, $events === [] ? 0 : max(array_keys($events)), $pruned === [] ? 0 : max(array_keys($pruned)), (int) $envelope->chain_sequence);

        if ($top === 0) {
            if ($signerIds !== [] || $envelope->chain_head !== null) {
                $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'NO_CHAIN'];
            }

            return;
        }

        if ($events === [] && $pruned === []) {
            $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'NO_CHAIN'];

            return;
        }

        $previous = $envelope->document_sha256;
        $hashes = [];
        $started = false;

        for ($sequence = 1; $sequence <= $top; $sequence++) {
            $event = $events[$sequence] ?? null;

            if (! $event instanceof SigningEvent) {
                if (isset($pruned[$sequence])) {
                    $hashes[$sequence] = $previous = $pruned[$sequence]['hash'];
                    $started = $started || $pruned[$sequence]['event'] === SigningEventType::ChainStarted->value;

                    continue;
                }

                $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'GAP', 'sequence' => $sequence];
                $previous = null;

                continue;
            }

            if ($previous !== null && $event->previous_hash !== $previous) {
                $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'LINK', 'sequence' => $sequence];
            }

            if (! hash_equals((string) $event->hash, EventHash::ofEvent($event))) {
                $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'HASH', 'sequence' => $sequence];
            }

            $digest = $event->payload['evidence'] ?? null;

            if (is_string($digest) && in_array($event->event, [SigningEventType::Signed, SigningEventType::Declined], true)) {
                $evidence[(int) $event->signing_request_id] = ['envelope_id' => $envelope->id, 'sequence' => $sequence, 'digest' => $digest];
            }

            if ($event->event === SigningEventType::ChainStarted) {
                $started = true;
                $this->checkPrior($envelope, $event, $signerIds, $legacy, $prunedRequests);
            }

            $hashes[$sequence] = $previous = (string) $event->hash;
        }

        if (! $started) {
            foreach ($signerIds as $requestId) {
                if (isset($legacy[$requestId])) {
                    $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'UNCHAINED', 'signing_request_id' => $requestId];
                }
            }
        }

        // The counter the trigger keeps must be the chain's last link.
        if ($counted !== $top || ($hashes[$top] ?? null) !== $envelope->last_hash) {
            $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'COUNTER'];
        }

        $this->checkHead($envelope, $hashes, $top, $heads);
    }

    /**
     * The evidence digests against the signer rows, in chunks (the drawn signature is decrypted here only).
     *
     * @param  array<int, array{envelope_id: string, sequence: int, digest: string}>  $evidence
     */
    private function checkEvidence(array $evidence): void
    {
        if ($evidence === []) {
            return;
        }

        SigningRequest::query()->whereKey(array_keys($evidence))->lazyById(200)->each(function (SigningRequest $request) use ($evidence): void {
            $expected = $evidence[$request->id];

            try {
                $current = EventHash::evidenceOf($request);
            } catch (Throwable) {
                // A signature that can no longer be decrypted (the app key is gone) is a changed signer row.
                $current = null;
            }

            if ($current === null || ! hash_equals($expected['digest'], $current)) {
                $this->problems[] = ['envelope_id' => $expected['envelope_id'], 'code' => 'EVIDENCE', 'sequence' => $expected['sequence']];
            }
        });
    }

    /**
     * @param  list<int>  $signerIds
     * @param  array<int, list<SigningEvent>>  $legacy
     * @param  array<int, true>  $prunedRequests
     */
    private function checkPrior(SigningEnvelope $envelope, SigningEvent $start, array $signerIds, array $legacy, array $prunedRequests): void
    {
        $named = [];

        foreach ((array) ($start->payload['signers'] ?? []) as $signer) {
            $requestId = (int) ($signer['signing_request_id'] ?? 0);
            $named[$requestId] = true;

            if (isset($prunedRequests[$requestId]) && ! in_array($requestId, $signerIds, true)) {
                continue;
            }

            $rows = $legacy[$requestId] ?? [];

            if (count($rows) !== (int) ($signer['events'] ?? -1) || ! hash_equals((string) ($signer['digest'] ?? ''), SigningChain::priorDigest($rows))) {
                $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'PRIOR', 'signing_request_id' => $requestId];
            }
        }

        foreach ($signerIds as $requestId) {
            if (! isset($named[$requestId]) && isset($legacy[$requestId])) {
                $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'UNCHAINED', 'signing_request_id' => $requestId];
            }
        }
    }

    /**
     * @param  array<int, string>  $hashes  sequence => hash, the pruned links included
     * @param  array<string, list<string>>  $heads
     */
    private function checkHead(SigningEnvelope $envelope, array $hashes, int $top, array $heads): void
    {
        if (! $envelope->status->isFinished() && $envelope->chain_head === null) {
            return;
        }

        if ($envelope->chain_head === null || $envelope->chain_sequence === null || ($hashes[$envelope->chain_sequence] ?? null) !== $envelope->chain_head) {
            $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'HEAD'];

            return;
        }

        if ($top > $envelope->chain_sequence) {
            $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'AFTER_HEAD', 'sequence' => $envelope->chain_sequence + 1];
        }

        if (! in_array($envelope->chain_head, $heads[$envelope->id] ?? [], true)) {
            $this->problems[] = ['envelope_id' => $envelope->id, 'code' => 'AUDIT'];
        }
    }

    /**
     * Every document version of an envelope that still has signers, and
     * every signer's Signeringsbevis, against its SHA-256. An envelope whose
     * signers are all pruned has lost its PDF on purpose and goes itself.
     *
     * @param  iterable<SigningRequest>  $requests
     * @param  array<string, list<int>>  $signers
     */
    private function checkFiles(iterable $requests, array $signers): int
    {
        $count = 0;

        foreach (SigningDocumentVersion::query()->orderBy('id')->cursor() as $version) {
            if (! isset($signers[$version->envelope_id])) {
                continue;
            }

            $count++;
            $code = $this->fileProblem($version->path, $version->sha256);

            if ($code !== null) {
                $this->problems[] = ['envelope_id' => $version->envelope_id, 'code' => $code, 'version_id' => $version->id];
            }
        }

        foreach ($requests as $request) {
            if ($request->signed_document_path === null || $request->signed_document_sha256 === null) {
                continue;
            }

            $count++;
            $code = $this->fileProblem($request->signed_document_path, $request->signed_document_sha256);

            if ($code !== null) {
                $this->problems[] = ['envelope_id' => $request->envelope_id, 'code' => $code, 'signing_request_id' => $request->id];
            }
        }

        return $count;
    }

    private function fileProblem(string $path, string $sha256): ?string
    {
        $disk = Storage::disk(SigningRequest::DISK);

        if (! $disk->exists($path)) {
            return 'FILE_MISSING';
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return 'FILE_MISSING';
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_equals($sha256, hash_final($context)) ? null : 'FILE_CHANGED';
    }

    /**
     * The links of pruned signers, from the audit log.
     *
     * @return array{0: array<string, array<int, array{hash: string, event: string}>>, 1: array<int, true>}
     */
    private function pruned(Company $company): array
    {
        $links = [];
        $requests = [];

        foreach (AuditLog::query()->forCompany($company)->where('action', SigningChain::PRUNED)->orderBy('id')->get(['payload']) as $entry) {
            $envelopeId = (string) ($entry->payload['envelope_id'] ?? '');
            $requests[(int) ($entry->payload['signing_request_id'] ?? 0)] = true;

            foreach ((array) ($entry->payload['links'] ?? []) as $link) {
                $links[$envelopeId][(int) $link['sequence']] = ['hash' => (string) $link['hash'], 'event' => (string) ($link['event'] ?? '')];
            }
        }

        return [$links, $requests];
    }

    /**
     * The heads the audit log holds, per envelope.
     *
     * @return array<string, list<string>>
     */
    private function heads(Company $company): array
    {
        $heads = [];
        $actions = [...array_values(SigningChain::HEAD_ACTIONS), SigningChain::BACKFILLED];

        foreach (AuditLog::query()->forCompany($company)->whereIn('action', $actions)->where('subject_type', (new SigningEnvelope)->getMorphClass())->get(['subject_id', 'payload']) as $entry) {
            $head = $entry->payload['chain_head'] ?? null;

            if (is_string($head)) {
                $heads[(string) $entry->subject_id][] = $head;
            }
        }

        return $heads;
    }
}
