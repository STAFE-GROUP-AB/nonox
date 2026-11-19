<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Fields\PageImages;
use App\Domain\Signing\SigningStatus;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Signing links that were never signed (ADR-046): expired, declined or
 * revoked more than a year ago, they and their PDFs are deleted, with the
 * signer's e-mail address and any decline reason. A signed link is
 * evidence and stays. A pruned signer of an envelope that stays leaves the
 * hashes of its chain links in the audit log (ADR-046 amendment, gap 4). Runs inside one company: from crm:agreements while
 * CRM is on, and from signing:prune for every company, since quotes are
 * signed with CRM off too (ADR-075 §7).
 */
final class PruneSigningLinks
{
    public const int AFTER_MONTHS = 12;

    public function __invoke(CarbonImmutable $today): int
    {
        $cutoff = $today->subMonthsNoOverflow(self::AFTER_MONTHS);
        $requests = SigningRequest::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('status', SigningStatus::Pending->value)->where('expires_at', '<', $cutoff))
                ->orWhere(fn ($q) => $q->where('status', SigningStatus::Declined->value)->where('declined_at', '<', $cutoff))
                ->orWhere(fn ($q) => $q->where('status', SigningStatus::Revoked->value)->where('revoked_at', '<', $cutoff)))
            ->get(['id', 'company_id', 'envelope_id', 'document_path', 'document_sha256']);

        foreach ($requests as $request) {
            $this->keepChainLinks($request);

            // The PDF is shared by every signer of an envelope (ADR-046 S1): it goes with the last row that uses it.
            $last = ! SigningRequest::query()->where('document_path', $request->document_path)->whereKeyNot($request->id)->exists();
            // The page images rendered for a document with fields (ADR-046 S6) go with it, unless another envelope's version is the same file.
            $images = $last && ! $this->imagesShared($request->document_sha256, $request->envelope_id);

            // The row first; its files only once that is committed, so a failed delete never leaves a row without its file.
            $request->delete();

            DB::afterCommit(function () use ($request, $last, $images): void {
                if ($last) {
                    Storage::disk(SigningRequest::DISK)->delete($request->document_path);
                }

                if ($images) {
                    Storage::disk(SigningRequest::DISK)->deleteDirectory(PageImages::directory($request->company_id, $request->document_sha256));
                }
            });
        }

        // An envelope left without signers (ADR-046 amendment, gap 1) goes with them; a completed one is evidence and stays (trigger).
        $stale = SigningEnvelope::query()
            ->where('status', '!=', EnvelopeStatus::Completed->value)
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('signers')
            ->get(['id', 'company_id', 'document_type', 'signable_id']);

        if ($stale->isNotEmpty()) {
            // Its stamped versions' files and every version's page images (ADR-046 S6) go with it: the rows cascade
            // first, the files after the commit.
            $files = [];
            $directories = [];

            foreach (SigningDocumentVersion::query()->whereIn('envelope_id', $stale->modelKeys())->get(['id', 'company_id', 'envelope_id', 'kind', 'path', 'sha256']) as $version) {
                if ($version->kind === SigningDocumentVersion::AFTER_SIGNER) {
                    $files[] = $version->path;
                }

                if (! $this->imagesShared($version->sha256, $version->envelope_id)) {
                    $directories[] = PageImages::directory($version->company_id, $version->sha256);
                }
            }

            SigningEnvelope::query()->whereKey($stale->modelKeys())->delete();
            $this->pruneStandaloneDocuments($stale);

            DB::afterCommit(function () use ($files, $directories): void {
                Storage::disk(SigningRequest::DISK)->delete($files);

                foreach (array_unique($directories) as $directory) {
                    Storage::disk(SigningRequest::DISK)->deleteDirectory($directory);
                }
            });
        }

        return $requests->count();
    }

    /** Whether a version of another envelope is the same file, so its page images are still needed. */
    private function imagesShared(string $sha256, ?string $envelopeId): bool
    {
        return SigningDocumentVersion::query()->where('sha256', $sha256)->when($envelopeId !== null, fn ($query) => $query->where('envelope_id', '!=', $envelopeId))->exists();
    }

    /**
     * A standalone document (module Signering, ADR-046 S7) that was sent but
     * never signed goes with its last envelope: the row and its file. One
     * that was never sent stays until someone deletes it, and one with an
     * envelope left (a signed one) stays with the signing evidence.
     *
     * @param  Collection<int, SigningEnvelope>  $envelopes  the envelopes just deleted
     */
    private function pruneStandaloneDocuments(Collection $envelopes): void
    {
        foreach ($envelopes->where('document_type', 'DOCUMENT')->whereNotNull('signable_id')->unique('signable_id') as $envelope) {
            if (SigningEnvelope::query()->where('document_type', 'DOCUMENT')->where('signable_id', $envelope->signable_id)->exists()) {
                continue;
            }

            DB::transaction(function () use ($envelope): void {
                $path = DB::table('signing_documents')->where('company_id', $envelope->company_id)->where('id', $envelope->signable_id)->value('path');

                if (! is_string($path)) {
                    return;
                }

                DB::table('signing_documents')->where('company_id', $envelope->company_id)->where('id', $envelope->signable_id)->delete();
                StoredFiles::deleteUnreferenced([$path]);
            });
        }
    }

    /**
     * A pruned signer of an envelope that keeps other signers takes its
     * events with it (they cascade with the row). Their sequence numbers and
     * hashes go to the append-only audit log first, so the envelope's chain
     * still links and signing:verify can tell a pruned signer from a
     * removed one (ADR-046 amendment, gap 4). Hashes only, no values.
     */
    private function keepChainLinks(SigningRequest $request): void
    {
        if ($request->envelope_id === null || ! SigningRequest::query()->where('envelope_id', $request->envelope_id)->whereKeyNot($request->id)->exists()) {
            return;
        }

        $links = SigningEvent::query()->where('signing_request_id', $request->id)->whereNotNull('sequence')->orderBy('sequence')->get(['sequence', 'hash', 'event']);

        Audit::record(SigningChain::PRUNED, $request, [
            'envelope_id' => $request->envelope_id,
            'signing_request_id' => $request->id,
            'links' => $links->map(fn (SigningEvent $event): array => ['sequence' => $event->sequence, 'hash' => $event->hash, 'event' => $event->event->value])->values()->all(),
        ], $request->company_id);
    }
}
