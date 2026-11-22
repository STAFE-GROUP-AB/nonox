<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Fields\PageImages;
use App\Domain\Signing\Retention\EnvelopeDeletion;
use App\Domain\Signing\Retention\SigningRetention;
use App\Domain\Signing\SigningStatus;
use App\Models\SigningDocumentVersion;
use App\Models\SigningDownloadCode;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Modules\Signing\Actions\ManageSigningBulkSends;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Signing links that were never signed (ADR-046): expired, declined or
 * revoked more than a year ago, they and their PDFs are deleted, with the
 * signer's e-mail address and any decline reason. A pruned signer of an
 * envelope that stays leaves the hashes of its chain links in the audit log
 * (ADR-046 amendment, gap 4).
 *
 * Retention (ADR-046 amendment, gap 11, package S16): a whole envelope whose
 * retention date has passed is deleted with everything in it
 * (EnvelopeDeletion), a signed one included; an envelope on legal hold is
 * never touched. A standalone document that was uploaded but never sent is
 * deleted UNSENT_DAYS after the upload.
 *
 * Runs inside one company: from crm:agreements while CRM is on, and from
 * signing:prune for every company, since quotes are signed with CRM off too
 * (ADR-075 §7).
 */
final class PruneSigningLinks
{
    public const int AFTER_MONTHS = 12;

    /** @return int the unsigned links pruned */
    public function __invoke(CarbonImmutable $today): int
    {
        return $this->run($today)['links'];
    }

    /**
     * Bulk sends (ADR-046 S15): a preview nobody confirmed within a day is thrown away with its rows (the addresses
     * and access codes in them), and a finished run's result list goes after AFTER_MONTHS. Rows cascade.
     */
    private function pruneBulkSends(CarbonImmutable $today): void
    {
        SigningBulkSend::query()->where('status', SigningBulkSend::PREVIEW)->where('created_at', '<', CarbonImmutable::now()->subHours(ManageSigningBulkSends::PREVIEW_HOURS))->delete();
        SigningBulkSend::query()->whereIn('status', [SigningBulkSend::COMPLETED, SigningBulkSend::CANCELLED])->where('finished_at', '<', $today->subMonthsNoOverflow(self::AFTER_MONTHS))->delete();
    }

    /**
     * @return array{links: int, envelopes: int, documents: int} the unsigned links, the whole envelopes past their
     *                                                           retention and the never-sent documents deleted
     */
    public function run(CarbonImmutable $today): array
    {
        $cutoff = $today->subMonthsNoOverflow(self::AFTER_MONTHS);
        $companyId = CurrentCompany::id();

        // An envelope an older worker finished during a deploy gets its retention first (ADR-046 S16).
        if ($companyId !== null) {
            SigningRetention::backfill($companyId);
        }

        $this->pruneBulkSends($today);

        $envelopes = $this->pruneExpiredRetention($today);
        // A held envelope (bevarandespärr) keeps every row; the database refuses it too.
        $held = SigningEnvelope::query()->where('retention_hold', true)->select('id');

        // The e-mail codes of a link that no longer works are of no use: they go the day it expires (S13 amendment); the rest go with the link row.
        SigningDownloadCode::query()->whereIn('download_link_id', SigningDownloadLink::query()->where('expires_at', '<', $today)->select('id'))->delete();

        // A download link (ADR-046 S13) works 14 days (7 for some); the row stays another 30 so that an old link says "expired", then goes with its recipient's address.
        SigningDownloadLink::query()->where('expires_at', '<', $today->subDays(SigningDownloadLink::PRUNE_AFTER_DAYS))->delete();

        $requests = SigningRequest::query()
            ->where(fn ($query) => $query->whereNull('envelope_id')->orWhereNotIn('envelope_id', $held))
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
            ->where('retention_hold', false)
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

        return ['links' => $requests->count(), 'envelopes' => $envelopes, 'documents' => $this->pruneUnsentDocuments($today)];
    }

    /**
     * Every envelope whose retention date has passed and that is not held,
     * deleted whole (ADR-046 S16). One envelope that the database refuses
     * (its own clock decides the date) is reported and left for the next
     * night, so it never stops the others.
     */
    private function pruneExpiredRetention(CarbonImmutable $today): int
    {
        $deleted = 0;
        $due = SigningEnvelope::query()
            ->where('retention_hold', false)
            ->whereNotNull('retention_until')
            ->where('retention_until', '<', $today->setTimezone(SigningRetention::ZONE)->toDateString())
            ->orderBy('retention_until')
            ->get();

        // What they are linked to may keep them longer: a booking in a later fiscal year, an agreement that still runs
        // or ended later (review 2026-11-19). Computed for the whole batch in a fixed number of queries; a date that
        // must move later is written and the envelope stays.
        $requiredDates = SigningRetention::requiredForMany($due, $today);

        foreach ($due as $envelope) {
            $required = SigningRetention::refresh($envelope, $today, $requiredDates[$envelope->id] ?? null, computed: true);

            if ($required === null || ! $required->lessThan($today->setTimezone(SigningRetention::ZONE)->startOfDay())) {
                continue;
            }

            try {
                EnvelopeDeletion::delete($envelope, EnvelopeDeletion::RETENTION);
                $deleted++;
            } catch (QueryException $exception) {
                report($exception);
            }
        }

        return $deleted;
    }

    /**
     * A standalone document (module Signering, ADR-046 S7) uploaded more than
     * UNSENT_DAYS ago and never sent is deleted with its file (GDPR art.
     * 5.1 e). TODO(legal-verify): the 90 days (proposed in S7).
     */
    private function pruneUnsentDocuments(CarbonImmutable $today): int
    {
        $companyId = CurrentCompany::id();

        if ($companyId === null) {
            return 0;
        }

        $old = DB::table('signing_documents')
            ->where('company_id', $companyId)
            ->where('created_at', '<', $today->subDays(SigningRetention::UNSENT_DAYS))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('signing_envelopes')
                ->where('signing_envelopes.company_id', $companyId)
                ->where('signing_envelopes.document_type', 'DOCUMENT')
                ->whereRaw('signing_envelopes.signable_id = signing_documents.id::text'))
            ->get(['id', 'path']);

        foreach ($old as $document) {
            DB::transaction(function () use ($companyId, $document): void {
                DB::table('signing_documents')->where('company_id', $companyId)->where('id', $document->id)->delete();
                StoredFiles::deleteUnreferenced([(string) $document->path]);
            });
        }

        return $old->count();
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
