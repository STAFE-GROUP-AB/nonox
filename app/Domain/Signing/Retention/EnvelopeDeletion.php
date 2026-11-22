<?php

declare(strict_types=1);

namespace App\Domain\Signing\Retention;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\Fields\PageImages;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a whole signing envelope (ADR-046 amendment, gap 11, package S16):
 * the envelope row, and by the foreign keys' cascade its signer rows, events,
 * link tokens, checks, fields, values, versions and copy recipients, then,
 * once that has committed, its files (the PDF, every stamped version, every
 * Signeringsbevis, the page images) that no remaining row refers to. A
 * standalone document goes with its last envelope. The envelope's own delete
 * trigger decides whether it may go (retention, legal hold, erasure); this
 * class never edits part of an envelope.
 *
 * The audit entry holds ids, the reason, the class and date, the number of
 * signers and the chain's head, never a name, address or title, so it can be
 * shown later that the envelope was deleted lawfully and what it was.
 */
final class EnvelopeDeletion
{
    /** Its retention date has passed. */
    public const string RETENTION = 'RETENTION';

    /** An erasure under GDPR art. 17 of an envelope nobody signed. */
    public const string ERASURE = 'ERASURE';

    public const string AUDIT = 'signing.envelope_deleted';

    /**
     * Runs in its own transaction (a savepoint inside a surrounding one). The
     * caller holds the document's lock where a signer could still act.
     */
    public static function delete(SigningEnvelope $envelope, string $reason, ?User $user = null): void
    {
        DB::transaction(function () use ($envelope, $reason, $user): void {
            // The row lock first, so a long-term validation version (ADR-046 S11) is either committed before the
            // versions are read here, or finds the envelope gone and removes its own file.
            SigningEnvelope::query()->whereKey($envelope->id)->lockForUpdate()->first(['id']);
            $requests = SigningRequest::query()->where('envelope_id', $envelope->id)->get(['id', 'document_path', 'signed_document_path']);
            $versions = SigningDocumentVersion::query()->where('envelope_id', $envelope->id)->get(['id', 'path', 'sha256']);
            $paths = array_values(array_unique(array_filter([
                $envelope->document_path,
                ...$requests->pluck('document_path')->all(),
                ...$requests->pluck('signed_document_path')->all(),
                ...$versions->pluck('path')->all(),
            ], fn (mixed $path): bool => is_string($path) && $path !== '')));
            $hashes = array_values(array_unique([$envelope->document_sha256, ...$versions->pluck('sha256')->all()]));

            if ($reason === self::ERASURE) {
                // Read by the envelope's delete trigger: an erasure may delete an envelope with a recent decline.
                DB::select("select set_config('nonox.signing_erasure', ?, true)", [$envelope->id]);
            }

            SigningEnvelope::query()->whereKey($envelope->id)->delete();

            Audit::record(self::AUDIT, $envelope, [
                'envelope_id' => $envelope->id,
                'reason' => $reason,
                'document_type' => $envelope->document_type,
                'status' => $envelope->status->value,
                'retention_class' => $envelope->retention_class,
                'retention_until' => $envelope->retention_until?->toDateString(),
                'signers' => $requests->count(),
                'chain_head' => $envelope->chain_head,
            ], $envelope->company_id, $user);

            self::deleteStandaloneDocument($envelope);
            self::deleteFiles($envelope->company_id, $paths, $hashes);
        });
    }

    /**
     * Deletes one signer row of an envelope that stays (an erasure of that
     * person, gap 11 review 2026-11-19): its events, link tokens, checks,
     * fields and values go by the cascade. Its chain links are written to the
     * audit log first (signing.events_pruned), so the envelope's chain still
     * verifies. Never a signed row (trigger).
     */
    public static function deleteSigner(SigningRequest $signer, ?User $user = null): void
    {
        DB::transaction(function () use ($signer, $user): void {
            $links = SigningEvent::query()->where('signing_request_id', $signer->id)->whereNotNull('sequence')->orderBy('sequence')->get(['sequence', 'hash', 'event']);

            if ($signer->envelope_id !== null && $links->isNotEmpty()) {
                Audit::record(SigningChain::PRUNED, $signer, [
                    'envelope_id' => $signer->envelope_id,
                    'signing_request_id' => $signer->id,
                    'links' => $links->map(fn (SigningEvent $event): array => ['sequence' => $event->sequence, 'hash' => $event->hash, 'event' => $event->event->value])->values()->all(),
                ], $signer->company_id, $user);
            }

            DB::select("select set_config('nonox.signing_erasure', ?, true)", ['request:'.$signer->id]);
            $paths = is_string($signer->signed_document_path) && $signer->signed_document_path !== '' ? [$signer->signed_document_path] : [];
            SigningRequest::query()->whereKey($signer->id)->delete();
            self::deleteFiles($signer->company_id, $paths, []);
        });
    }

    /**
     * The files no remaining signing row (of any company) or other stored
     * row refers to, after the commit; the page images of a version no other
     * envelope still has.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $hashes
     */
    private static function deleteFiles(int $companyId, array $paths, array $hashes): void
    {
        // The same lock per path as StoredFiles::deleteUnreferenced, so a row that links the file at the same time
        // (a Signeringsbevis attached to a voucher) and this check never interleave.
        foreach ($paths as $path) {
            StoredFiles::lock($path);
        }

        $kept = $paths === [] ? [] : DB::table('crm_signing_requests')->whereIn('document_path', $paths)->pluck('document_path')
            ->merge(DB::table('crm_signing_requests')->whereIn('signed_document_path', $paths)->pluck('signed_document_path'))
            ->merge(DB::table('signing_envelopes')->whereIn('document_path', $paths)->pluck('document_path'))
            ->merge(DB::table('signing_document_versions')->whereIn('path', $paths)->pluck('path'))
            ->merge(StoredFiles::referenced($paths))
            ->all();
        $files = array_values(array_diff($paths, $kept));
        $directories = [];

        foreach ($hashes as $sha256) {
            $used = DB::table('signing_document_versions')->where('company_id', $companyId)->where('sha256', $sha256)->exists()
                || DB::table('signing_envelopes')->where('company_id', $companyId)->where('document_sha256', $sha256)->exists();

            if (! $used) {
                $directories[] = PageImages::directory($companyId, $sha256);
            }
        }

        DB::afterCommit(function () use ($files, $directories): void {
            Storage::disk(SigningRequest::DISK)->delete($files);

            foreach ($directories as $directory) {
                Storage::disk(SigningRequest::DISK)->deleteDirectory($directory);
            }
        });
    }

    /** A standalone document (module Signering) goes with its last envelope: the row, and its file once committed. */
    private static function deleteStandaloneDocument(SigningEnvelope $envelope): void
    {
        if ($envelope->document_type !== 'DOCUMENT' || $envelope->signable_id === null) {
            return;
        }

        if (DB::table('signing_envelopes')->where('company_id', $envelope->company_id)->where('document_type', 'DOCUMENT')->where('signable_id', $envelope->signable_id)->exists()) {
            return;
        }

        $path = DB::table('signing_documents')->where('company_id', $envelope->company_id)->where('id', $envelope->signable_id)->value('path');

        if (! is_string($path)) {
            return;
        }

        DB::table('signing_documents')->where('company_id', $envelope->company_id)->where('id', $envelope->signable_id)->delete();
        StoredFiles::deleteUnreferenced([$path]);
    }
}
