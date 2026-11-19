<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningStatus;
use App\Jobs\RenderSigningPages;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEvent;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\SigningRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What the signer page shows of an envelope's fields (ADR-046 amendment,
 * gap 2, package S6): the version of the document the signer is shown
 * (the original, or the one after the last signer, so earlier signers'
 * values are visible and locked), its pages, the signer's own fields, the
 * earlier signers' fields with what they filled in, and the later signers'
 * fields as empty outlines named "Undertecknare 2" and so on (roles come
 * with templates, gap 8). Five queries whatever the number of signers and
 * fields; no drawn signature is decrypted.
 */
final readonly class SignerFields
{
    /**
     * @param  Collection<int, SigningField>  $fields  every field of the envelope, in reading order
     * @param  array<int, array{value: ?string, image: bool}>  $values  field id => what was filled in
     * @param  array<int, int>  $numbers  signer row id => its number in the envelope (1, 2, …)
     * @param  array<int, SigningStatus>  $statuses  signer row id => status
     * @param  ?list<array{page: int, width: int, height: int}>  $pages  null when the pages cannot be shown now
     */
    public function __construct(
        public SigningRequest $signer,
        public SigningDocumentVersion $version,
        public Collection $fields,
        public array $values,
        public array $numbers,
        public array $statuses,
        public ?array $pages,
    ) {}

    /** The fields view of a signer, or null when the envelope has no fields (the page is then as it always was). */
    public static function for(SigningRequest $signer, PageImages $images): ?self
    {
        if ($signer->envelope_id === null) {
            return null;
        }

        $fields = SigningField::query()->where('envelope_id', $signer->envelope_id)->orderBy('page')->orderBy('position')->get();

        if ($fields->isEmpty()) {
            return null;
        }

        $version = self::shownVersion($signer);

        if (! $version instanceof SigningDocumentVersion) {
            return null;
        }

        $values = [];

        foreach (SigningFieldValue::query()->whereIn('field_id', $fields->modelKeys())->selectRaw('field_id, value, image IS NOT NULL AS has_image')->toBase()->get() as $row) {
            $values[(int) $row->field_id] = ['value' => $row->value === null ? null : (string) $row->value, 'image' => (bool) $row->has_image];
        }

        $numbers = [];
        $statuses = [];

        foreach (SigningRequest::query()->where('envelope_id', $signer->envelope_id)->orderBy('step')->orderBy('id')->get(['id', 'step', 'status']) as $index => $row) {
            $numbers[$row->id] = $index + 1;
            $statuses[$row->id] = $row->status;
        }

        // Only what the queued render (RenderSigningPages) has made: a request never starts the engine.
        $pages = $images->pages($version);

        // Not rendered (the job failed or is still queued): ask again, at most every five minutes per version.
        if ($pages === null && Cache::add("signing-pages-requested:{$version->company_id}:{$version->sha256}", true, 300)) {
            RenderSigningPages::dispatch($version->company_id, $version->id);
        }

        return new self($signer, $version, $fields, $values, $numbers, $statuses, $pages);
    }

    /**
     * The version a signer is shown: while pending the latest (the original, or after the last signer); once signed,
     * the version with their own values.
     */
    public static function shownVersion(SigningRequest $signer): ?SigningDocumentVersion
    {
        if ($signer->envelope_id === null) {
            return null;
        }

        if ($signer->status === SigningStatus::Signed) {
            $own = SigningDocumentVersion::query()->where('envelope_id', $signer->envelope_id)->where('kind', SigningDocumentVersion::AFTER_SIGNER)->where('signing_request_id', $signer->id)->first();

            if ($own instanceof SigningDocumentVersion) {
                return $own;
            }

            // A signer who stamped nothing signed the version their FIELDS_FILLED event names, not whatever is latest now.
            $signedVersion = SigningEvent::query()->where('signing_request_id', $signer->id)->where('event', SigningEventType::FieldsFilled->value)->latest('id')->first(['payload'])?->payload['version_sha256'] ?? null;

            if (is_string($signedVersion)) {
                $named = SigningDocumentVersion::query()->where('envelope_id', $signer->envelope_id)->where('sha256', $signedVersion)->orderBy('id')->first();

                if ($named instanceof SigningDocumentVersion) {
                    return $named;
                }
            }
        }

        return SigningFields::latestVersion($signer->envelope_id);
    }

    /** @return Collection<int, SigningField> */
    public function own(): Collection
    {
        return $this->fields->where('signing_request_id', $this->signer->id)->values();
    }

    /** @return Collection<int, SigningField> */
    public function onPage(int $page): Collection
    {
        return $this->fields->where('page', $page)->values();
    }

    public function isOwn(SigningField $field): bool
    {
        return $field->signing_request_id === $this->signer->id;
    }

    /** A field of a signer who has signed: its value is in the page image and cannot change. */
    public function isLocked(SigningField $field): bool
    {
        return ! $this->isOwn($field) && ($this->statuses[$field->signing_request_id] ?? null) === SigningStatus::Signed;
    }

    /** "Undertecknare 2": the signer a field belongs to, without their name or address. */
    public function signerName(SigningField $field): string
    {
        return __('Undertecknare :number', ['number' => $this->numbers[$field->signing_request_id] ?? 0]);
    }

    /** What an earlier signer filled in, as text for the field list. */
    public function shownValue(SigningField $field): ?string
    {
        $value = $this->values[$field->id] ?? null;

        if ($value === null) {
            return null;
        }

        return match (true) {
            $value['image'] => (string) ($field->type === FieldType::Initials ? __('Initialer (ritade)') : __('Namnteckning (ritad)')),
            $field->type === FieldType::Checkbox => (string) __('Ikryssad'),
            $field->type === FieldType::Radio => (string) __('Vald: :value', ['value' => $field->radioValue()]),
            $field->type === FieldType::Number => FieldInput::displayNumber((string) $value['value']),
            default => $value['value'],
        };
    }

    /** Whether the signer has initials fields (the page then shows the initials pad). */
    public function needsInitials(): bool
    {
        return $this->own()->contains(fn (SigningField $field): bool => $field->type === FieldType::Initials);
    }

    /** Whether one of the signer's initials fields is required (initials must then be given). */
    public function initialsRequired(): bool
    {
        return $this->own()->contains(fn (SigningField $field): bool => $field->type === FieldType::Initials && $field->required);
    }

    /** The first of the signer's required fields, for "Nästa obligatoriska fält". */
    public function firstRequired(): ?SigningField
    {
        return $this->own()->first(fn (SigningField $field): bool => $field->required && ! $field->type->isAutomatic() && ! $field->type->isMark());
    }

    /**
     * The radio groups of the signer's own fields on a page: group key => its buttons.
     *
     * @return array<string, Collection<int, SigningField>>
     */
    public function radioGroups(): array
    {
        return $this->own()->where('type', FieldType::Radio)->groupBy('group_key')->all();
    }
}
