<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Signing\Chain\CanonicalJson;
use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\StampImage;
use App\Domain\Signing\Pdf\StampItem;
use App\Domain\Signing\Pdf\StampText;
use App\Domain\Signing\SigningUnavailable;
use App\Jobs\RenderSigningPages;
use App\Models\SigningDocumentVersion;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\SigningRequest;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stamps a signer's field values into the document as a new incremental
 * revision (ADR-046 amendment, principle 2 and gap 2, package S6): the
 * revision the signer was shown stays byte for byte the first part of the
 * new file. The new file is a version AFTER_SIGNER in
 * signing_document_versions, and the next signer is shown it, so earlier
 * signers' values are visible and locked. Values are flattened (no
 * interactive form fields).
 *
 * Runs inside the signing transaction. A file written for a transaction
 * that then rolls back (a refused value later, a deadlock that is retried)
 * is removed when it rolls back (DB::afterRollBack).
 */
final class FieldStamper
{
    public function __construct(private readonly PdfEngine $engine) {}

    /**
     * @param  list<array{field: SigningField, value: ?string, image: ?string}>  $values
     *
     * @throws ValidationException when the engine cannot stamp now (the signature is not saved)
     */
    public function stamp(SigningRequest $signer, SigningDocumentVersion $shown, array $values): SigningDocumentVersion
    {
        $disk = Storage::disk(SigningRequest::DISK);
        $temporary = [];
        $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-signing-'.Str::uuid()->toString().'.pdf';

        try {
            $items = [];

            foreach ($values as $value) {
                foreach (self::items($value['field'], $value['value'], $value['image'], $temporary) as $item) {
                    $items[] = $item;
                }
            }

            if ($items === []) {
                return $shown;
            }

            // The version the signer was shown must still be the stored file: read once, checked against its SHA-256,
            // and the same bytes must begin the stamped file. A byte prefix proves the earlier revision can be
            // recovered from the new file, not that its pages look the same in a reader (see ADR-046 S6).
            $shownBytes = $disk->get($shown->path);

            if (! is_string($shownBytes) || ! hash_equals($shown->sha256, hash('sha256', $shownBytes))) {
                throw new SigningUnavailable(SigningUnavailable::GONE);
            }

            $result = $this->engine->stamp($disk->path($shown->path), $output, $items);
            $path = 'crm-signing/'.$signer->company_id.'/'.Str::uuid()->toString().'.pdf';
            $bytes = (string) file_get_contents($result->path);

            if (! str_starts_with($bytes, $shownBytes)) {
                throw ValidationException::withMessages(['fields' => __('Dokumentet kunde inte fyllas i just nu. Försök igen om en stund.')]);
            }

            $disk->put($path, $bytes);
            DB::afterRollBack(fn () => Storage::disk(SigningRequest::DISK)->delete($path));

            $version = SigningDocumentVersion::create([
                'envelope_id' => $shown->envelope_id,
                'kind' => SigningDocumentVersion::AFTER_SIGNER,
                'signing_request_id' => $signer->id,
                'path' => $path,
                'sha256' => hash('sha256', $bytes),
                'bytes' => strlen($bytes),
            ]);

            // The next signer's page images, rendered in the background once the signature has committed.
            RenderSigningPages::dispatch($signer->company_id, $version->id)->afterCommit();

            return $version;
        } catch (PdfEngineException) {
            throw ValidationException::withMessages(['fields' => __('Dokumentet kunde inte fyllas i just nu. Försök igen om en stund.')]);
        } finally {
            foreach ([$output, ...$temporary] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * The hash of one value as the chain's FIELDS_FILLED event names it: the field, the value, the image and the time.
     */
    public static function valueHash(int $fieldId, ?string $value, ?string $image, DateTimeInterface|string $filledAt): string
    {
        return hash('sha256', CanonicalJson::encode([
            'field_id' => $fieldId,
            'filled_at' => CanonicalJson::seconds($filledAt),
            'image_sha256' => $image === null ? null : hash('sha256', $image),
            'value' => $value,
        ]));
    }

    /** The hash of a stored value. */
    public static function hashOf(SigningFieldValue $value): string
    {
        return self::valueHash($value->field_id, $value->value, $value->image, $value->filled_at);
    }

    /**
     * What is drawn for one value, in PDF points.
     *
     * @param  list<string>  $temporary  image files written here, removed by the caller
     * @return list<StampItem>
     */
    private static function items(SigningField $field, ?string $value, ?string $image, array &$temporary): array
    {
        $x = $field->x / 100;
        $y = $field->y / 100;
        $width = $field->width / 100;
        $height = $field->height / 100;

        if ($image !== null) {
            $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-signing-'.Str::uuid()->toString().'.png';
            file_put_contents($file, (string) base64_decode($image, true));
            $temporary[] = $file;

            return [new StampImage($field->page, $x, $y, $width, $height, $file)];
        }

        if ($value === null || $value === '') {
            return [];
        }

        $text = match ($field->type) {
            FieldType::Checkbox, FieldType::Radio => 'X',
            FieldType::Number => FieldInput::displayNumber($value),
            default => $value,
        };

        // Inside the field's box (TextFit): shrunk down to 6 points to fit its width, and the engine clips to the box.
        $size = TextFit::size($text, $width, $height, $field->type->isMark() ? 20 : 11);

        if (in_array($field->type, [FieldType::Checkbox, FieldType::Radio], true)) {
            $mark = min($width, max($size, TextFit::width('X', $size) + 2 * TextFit::PADDING));

            return [new StampText($field->page, $x + max(0, ($width - $mark) / 2), $y, $text, $size, boxWidth: $mark, boxHeight: $height)];
        }

        return [new StampText($field->page, $x + TextFit::PADDING, $y, $text, $size, boxWidth: max(1, $width - TextFit::PADDING), boxHeight: $height)];
    }
}
