<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningUnavailable;
use App\Jobs\RenderSigningPages;
use App\Models\SigningDocumentVersion;
use App\Models\SigningField;
use App\Models\SigningProviderSession;
use App\Models\SigningRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a provider signature as the signer's revision (ADR-046 S17), in the
 * transaction that signs (SignAtLink), while the signer row is locked and
 * still pending, in the lock order every signing path takes: document,
 * signer, run, envelope (the chain).
 *
 * The run must be this signer's, waiting for its signature; the version the
 * provider signed over must still be the latest (a parallel signer who
 * signed in between changed it); the signed file must start with that
 * version's exact bytes. It becomes an AFTER_SIGNER version (so the seal,
 * the copies and signing:verify use it like any other), the run becomes
 * DONE with its working secrets emptied, and the chain gets PROVIDER_SIGNED
 * with the run's id, the stated level, the certificate's fingerprint and
 * both versions' SHA-256.
 */
final class ProviderSignatures
{
    public function __construct(private readonly SigningChain $chain) {}

    /**
     * @throws ValidationException
     * @throws SigningUnavailable
     */
    public function store(SigningRequest $signer, ProviderSigned $signed): SigningDocumentVersion
    {
        if ($signer->envelope_id === null) {
            throw new SigningUnavailable(SigningUnavailable::GONE);
        }

        $session = SigningProviderSession::query()
            ->whereKey($signed->sessionId)
            ->where('signing_request_id', $signer->id)
            ->lockForUpdate()
            ->first();

        if (! $session instanceof SigningProviderSession || $session->step !== SigningProviderSession::CREDENTIAL || $session->certificate_sha256 === null) {
            throw ValidationException::withMessages(['provider' => (new ProviderFailure(ProviderFailure::EXPIRED))->message()]);
        }

        // A provider signer has no fields of their own (refused at sending): nothing of theirs is stamped after their signature.
        if (SigningField::query()->where('signing_request_id', $signer->id)->exists()) {
            throw ValidationException::withMessages(['provider' => __('En undertecknare som skriver under hos en extern tjänst kan inte ha egna fält i dokumentet.')]);
        }

        $shown = SigningFields::latestVersion($signer->envelope_id);

        if (! $shown instanceof SigningDocumentVersion || ! hash_equals($session->shown_sha256, $shown->sha256)) {
            throw ValidationException::withMessages(['provider' => (new ProviderFailure(ProviderFailure::DOCUMENT_CHANGED))->message()]);
        }

        $disk = Storage::disk(SigningRequest::DISK);
        $shownBytes = $disk->get($shown->path);
        $bytes = @file_get_contents($signed->path);

        if (! is_string($shownBytes) || ! hash_equals($shown->sha256, hash('sha256', $shownBytes))) {
            throw new SigningUnavailable(SigningUnavailable::GONE);
        }

        if (! is_string($bytes) || ! hash_equals($signed->sha256, hash('sha256', $bytes)) || strlen($bytes) !== $signed->bytes || ! str_starts_with($bytes, $shownBytes) || strlen($bytes) <= strlen($shownBytes)) {
            throw ValidationException::withMessages(['provider' => (new ProviderFailure(ProviderFailure::SIGNATURE_INVALID))->message()]);
        }

        $path = 'crm-signing/'.$signer->company_id.'/'.Str::uuid()->toString().'.pdf';
        $disk->put($path, $bytes);
        DB::afterRollBack(fn () => Storage::disk(SigningRequest::DISK)->delete($path));

        $version = SigningDocumentVersion::create([
            'envelope_id' => $signer->envelope_id,
            'kind' => SigningDocumentVersion::AFTER_SIGNER,
            'signing_request_id' => $signer->id,
            'path' => $path,
            'sha256' => $signed->sha256,
            'bytes' => $signed->bytes,
        ]);

        $session->forceFill([
            'step' => SigningProviderSession::DONE,
            'signed_version_id' => $version->id,
            'completed_at' => CarbonImmutable::now(),
            'secrets' => null,
            'state_hash' => null,
        ])->save();

        $this->chain->record($signer, SigningEventType::ProviderSigned, payload: [
            'provider_session_id' => $session->id,
            'provider_id' => $session->provider_id,
            'claimed_level' => $session->claimed_level->value,
            'certificate_sha256' => $session->certificate_sha256,
            'shown_sha256' => $shown->sha256,
            'version_sha256' => $version->sha256,
        ]);

        // The next signer's page images, when the envelope has fields (rendered after the signature has committed).
        if (SigningField::query()->where('envelope_id', $signer->envelope_id)->exists()) {
            RenderSigningPages::dispatch($signer->company_id, $version->id)->afterCommit();
        }

        return $version;
    }
}
