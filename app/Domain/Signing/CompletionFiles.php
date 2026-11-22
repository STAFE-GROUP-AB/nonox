<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Domain\Signing\Fields\SignerFields;
use App\Domain\Signing\Fields\SigningFields;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * The files everyone gets when a document is signed (ADR-046 amendment, gap
 * 5, S13), in one place so that the mail attachments and the download link
 * hand out the same files:
 *
 * - SIGNER: the finished version the signer was shown (with their own values
 *   when the document has fields) and their signing certificate; the sealed
 *   file instead when the envelope has one signer and is sealed (S10).
 * - COPY: the finished version with every signer's values (sealed, S10, when
 *   the envelope is) and the summary
 *   of the signing (never another signer's full certificate).
 * - COMPANY: the finished version and every signer's full certificate.
 *
 * Must run inside the company (CurrentCompany::run). A file is either stored
 * ('path' on the signing disk) or made when handed out ('make').
 */
final class CompletionFiles
{
    /** The most a mail carries as attachments: 15 MB (ADR-046, gap 5). */
    public const int MAIL_LIMIT_BYTES = 15 * 1024 * 1024;

    /** @return list<array{name: string, path: ?string, make: ?Closure(): string}> */
    public static function forSigner(SigningRequest $request): array
    {
        $document = SignerFields::shownVersion($request)->path ?? $request->document_path;
        $envelope = $request->envelope_id === null ? null : SigningEnvelope::query()->find($request->envelope_id, ['id', 'sealed_path', 'ltv_level', 'document_path']);

        // A sealed envelope with one signer (ADR-046 S10): the signer gets the sealed file, which is their version
        // with the summary page and the seal added, or its newest long-term validation version (S11), which starts
        // with it. With several signers each signer's own copy stays the version they were shown, and the finished
        // file goes to everyone in the envelope copies.
        if ($envelope instanceof SigningEnvelope && $envelope->sealed_path !== null && ! SigningEnvelopes::hasSeveralSigners($envelope->id)) {
            $document = SigningFields::finalVersion($envelope);
        }

        $files = [['name' => $request->document_name, 'path' => $document, 'make' => null]];

        if ($request->signed_document_path !== null) {
            $files[] = ['name' => SigningPdfs::certificateFilename($request), 'path' => $request->signed_document_path, 'make' => null];
        }

        return $files;
    }

    /** @return list<array{name: string, path: ?string, make: ?Closure(): string}> */
    public static function forEnvelope(SigningEnvelope $envelope, string $kind): array
    {
        $envelope->loadMissing('signers');
        $files = [['name' => $envelope->document_name, 'path' => SigningFields::finalVersion($envelope), 'make' => null]];

        if ($kind === SigningDownloadLink::COPY) {
            // A sealed file has the summary as its last page (ADR-046 S10); a separate one would only repeat it.
            if ($envelope->sealed_path !== null) {
                return $files;
            }

            $files[] = ['name' => (string) __('Sammanstallning-av-signeringen.pdf'), 'path' => null, 'make' => fn (): string => SigningPdfs::envelopeSummary($envelope)];

            return $files;
        }

        foreach ($envelope->signers as $position => $signer) {
            if ($signer->status !== SigningStatus::Signed || $signer->signed_document_path === null) {
                continue;
            }

            $files[] = ['name' => (string) __('Signeringsbevis-:number.pdf', ['number' => $position + 1]), 'path' => $signer->signed_document_path, 'make' => null];
        }

        return $files;
    }

    /**
     * The size of the stored files; a file made on the spot (the summary, a few
     * pages) does not count.
     *
     * @param  list<array{name: string, path: ?string, make: ?Closure(): string}>  $files
     */
    public static function storedBytes(array $files): int
    {
        $disk = Storage::disk(SigningRequest::DISK);

        return array_sum(array_map(fn (array $file): int => $file['path'] === null || ! $disk->exists($file['path']) ? 0 : (int) $disk->size($file['path']), $files));
    }

    /** @param  list<array{name: string, path: ?string, make: ?Closure(): string}>  $files */
    public static function tooLargeForMail(array $files): bool
    {
        return self::storedBytes($files) > self::limit();
    }

    /** The limit, 15 MB; a smaller one in config only for tests. */
    public static function limit(): int
    {
        return (int) config('nonox.signing.mail_attachment_limit_bytes', self::MAIL_LIMIT_BYTES);
    }

    /** @param  array{name: string, path: ?string, make: ?Closure(): string}  $file */
    public static function bytes(array $file): string
    {
        if ($file['make'] instanceof Closure) {
            return ($file['make'])();
        }

        return (string) Storage::disk(SigningRequest::DISK)->get((string) $file['path']);
    }
}
