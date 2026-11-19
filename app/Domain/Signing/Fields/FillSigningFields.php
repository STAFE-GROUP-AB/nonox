<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\SigningEventType;
use App\Models\SigningDocumentVersion;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\SigningRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A signer's fields, written in the transaction that signs (ADR-046
 * amendment, gap 2, package S6), while the signer row is locked and still
 * pending:
 *
 * 1. Values posted for fields that are not the signer's own are left out
 *    and recorded as FIELD_REJECTED (field ids and a count only).
 * 2. The signer's own values are checked against each field's type and
 *    rules (FieldInput).
 * 3. The signer must have been shown the latest version: a parallel signer
 *    who signed in between changed it, so the page is reloaded.
 * 4. The values are stamped into a new incremental revision
 *    (FieldStamper), stored once (signing_field_values), and chained as
 *    FIELDS_FILLED with each value's hash and the two versions' SHA-256.
 */
final class FillSigningFields
{
    public function __construct(
        private readonly FieldStamper $stamper,
        private readonly SigningChain $chain,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the posted form
     * @param  array{method: string, image: ?string}  $signature
     * @return ?array{version: SigningDocumentVersion, values: int} null for an envelope without fields
     *
     * @throws ValidationException
     */
    public function __invoke(SigningRequest $signer, array $input, string $name, array $signature): ?array
    {
        if ($signer->envelope_id === null) {
            return null;
        }

        $fields = SigningField::query()->where('envelope_id', $signer->envelope_id)->orderBy('page')->orderBy('position')->get();

        if ($fields->isEmpty()) {
            return null;
        }

        $own = $fields->where('signing_request_id', $signer->id)->values();
        $rejected = FieldInput::rejected($own, $fields, $input);

        if ($rejected['field_ids'] !== [] || $rejected['other'] > 0) {
            $this->chain->record($signer, SigningEventType::FieldRejected, payload: ['field_ids' => $rejected['field_ids'], 'other' => $rejected['other']]);
        }

        $now = CarbonImmutable::now()->startOfSecond();
        $values = FieldInput::values($own, $input, $name, $signature, $now);
        $shown = SigningFields::latestVersion($signer->envelope_id);

        if (! $shown instanceof SigningDocumentVersion || ! hash_equals($shown->sha256, strtolower((string) ($input['revision_sha256'] ?? '')))) {
            throw ValidationException::withMessages(['revision_sha256' => __('Någon annan har skrivit under dokumentet sedan sidan öppnades. Ladda om sidan, läs dokumentet igen och signera sedan.')]);
        }

        $version = $this->stamper->stamp($signer, $shown, $values);
        $hashes = [];

        foreach ($values as $value) {
            $row = SigningFieldValue::create([
                'company_id' => $signer->company_id,
                'field_id' => $value['field']->id,
                'signing_request_id' => $signer->id,
                'value' => $value['value'],
                'image' => $value['image'],
                'filled_at' => $now,
            ]);

            $hashes[] = ['field_id' => $row->field_id, 'hash' => FieldStamper::valueHash($row->field_id, $value['value'], $value['image'], $now)];
        }

        $this->chain->record($signer, SigningEventType::FieldsFilled, payload: [
            'values' => $hashes,
            'shown_sha256' => $shown->sha256,
            'version_sha256' => $version->sha256,
        ]);

        return ['version' => $version, 'values' => count($hashes)];
    }
}
