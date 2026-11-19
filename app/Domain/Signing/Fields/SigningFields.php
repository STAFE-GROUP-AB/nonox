<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Signable;
use App\Jobs\RenderSigningPages;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningField;
use App\Models\SigningRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Places the fields of an envelope (ADR-046 amendment 2026-11-18, gap 2,
 * package S6), inside the transaction that sends it and before the first
 * event of its chain: after that the trigger freezes them.
 *
 * A definition names its signer by index (0 = the first signer given),
 * its type, page, rectangle (x, y, width, height in 1/100 PDF points from
 * the page's lower left corner), label, required, and per type: options (a
 * dropdown 1–30; a radio button exactly one, its value), group_key (radio
 * buttons with the same key are one choice, at most 30, all for one
 * signer) and rules (text: max_length 1–500; number: min, max, decimals
 * 0–6). The page sizes come from the PDF engine (gap 5), so fields need it:
 * without it the send is refused with "Kräver PDF-motorn".
 *
 * Pages that are rotated get no fields: the rectangle would be stamped in
 * the unrotated page space (a later package can map it).
 */
final class SigningFields
{
    /** At most this many fields per envelope. */
    public const int MAX_FIELDS = 200;

    public const int MAX_OPTIONS = 30;

    public const int MAX_OPTION_LENGTH = 100;

    public const int MAX_TEXT = 500;

    public const int MAX_DECIMALS = 6;

    /** The smallest box, in 1/100 PDF points (5 × 5 points). */
    public const int MIN_SIZE = 500;

    /** The largest box, in 1/100 PDF points: what a stamp may be (StampText, StampImage: 2 000 points). */
    public const int MAX_SIZE = 200_000;

    /** The farthest position on a page, in 1/100 PDF points (StampText, StampImage: 10 000 points). */
    public const int MAX_POSITION = 1_000_000;

    /** A name or a signature box holds at least this many ordinary characters at 6 points. */
    public const int MIN_NAME_CHARACTERS = 30;

    /** A date box holds the date (ÅÅÅÅ-MM-DD) at 6 points; initials at least two wide letters. */
    public const string DATE_SAMPLE = '0000-00-00';

    public const string INITIALS_SAMPLE = 'MM';

    public function __construct(private readonly PdfEngine $engine) {}

    /**
     * Validates the definitions (before the transaction, so an error costs nothing).
     *
     * @param  array<mixed>  $definitions
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function validate(array $definitions, int $signers): array
    {
        if ($definitions === []) {
            return [];
        }

        $validated = Validator::make(['fields' => $definitions], [
            'fields' => ['array', 'max:'.self::MAX_FIELDS],
            'fields.*' => ['array'],
            'fields.*.signer' => ['required', 'integer', 'min:0', 'max:'.($signers - 1)],
            'fields.*.type' => ['required', 'string', 'in:'.implode(',', array_map(fn (FieldType $type): string => $type->value, FieldType::cases()))],
            'fields.*.page' => ['required', 'integer', 'min:1', 'max:100'],
            'fields.*.x' => ['required', 'integer', 'min:0', 'max:'.self::MAX_POSITION],
            'fields.*.y' => ['required', 'integer', 'min:0', 'max:'.self::MAX_POSITION],
            'fields.*.width' => ['required', 'integer', 'min:'.self::MIN_SIZE, 'max:'.self::MAX_SIZE],
            'fields.*.height' => ['required', 'integer', 'min:'.self::MIN_SIZE, 'max:'.self::MAX_SIZE],
            'fields.*.label' => ['required', 'string', 'max:100', 'regex:/^[^\p{C}]+$/u'],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.options' => ['nullable', 'array', 'max:'.self::MAX_OPTIONS],
            'fields.*.options.*' => ['required', 'string', 'max:'.self::MAX_OPTION_LENGTH, 'regex:/^[^\p{C}]+$/u'],
            'fields.*.group_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'fields.*.rules' => ['nullable', 'array'],
            'fields.*.rules.max_length' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_TEXT],
            'fields.*.rules.min' => ['nullable', 'regex:/^-?\d{1,15}(\.\d{1,6})?$/'],
            'fields.*.rules.max' => ['nullable', 'regex:/^-?\d{1,15}(\.\d{1,6})?$/'],
            'fields.*.rules.decimals' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_DECIMALS],
        ], [
            'fields.max' => __('Högst :max fält i ett dokument.', ['max' => self::MAX_FIELDS]),
        ], [
            'fields.*.label' => __('fältets namn'),
            'fields.*.signer' => __('undertecknare'),
            'fields.*.page' => __('sida'),
        ])->validate();

        $clean = [];
        $groups = [];

        foreach (array_values((array) $validated['fields']) as $index => $definition) {
            $type = FieldType::from((string) $definition['type']);
            $options = array_values(array_map(fn (mixed $option): string => trim((string) $option), (array) ($definition['options'] ?? [])));
            $rules = (array) ($definition['rules'] ?? []);
            $groupKey = $definition['group_key'] ?? null;
            $fail = fn (string $message): ValidationException => ValidationException::withMessages(["fields.{$index}" => $message]);

            if ($type === FieldType::Dropdown && ($options === [] || count(array_unique($options)) !== count($options))) {
                throw $fail(__('En lista behöver 1–:max olika alternativ.', ['max' => self::MAX_OPTIONS]));
            }

            if ($type === FieldType::Radio && (count($options) !== 1 || $groupKey === null)) {
                throw $fail(__('Ett alternativ behöver en grupp och exakt ett värde.'));
            }

            if (! $type->hasOptions() && $options !== []) {
                throw $fail(__('Bara listor och alternativ har val.'));
            }

            if ($type !== FieldType::Radio && $groupKey !== null) {
                throw $fail(__('Bara alternativ hör till en grupp.'));
            }

            // Text never runs outside its box (TextFit): the longest text is what fits in the box at 6 points.
            $boxWidth = (int) $definition['width'] / 100;
            $fitting = TextFit::maxLength($boxWidth);

            // What NONOX fills in is not refused at signing, so its box must be wide enough already now.
            $tooNarrow = match ($type) {
                FieldType::Date => ! TextFit::fits(self::DATE_SAMPLE, $boxWidth),
                FieldType::Name, FieldType::Signature => TextFit::maxLength($boxWidth) < self::MIN_NAME_CHARACTERS,
                FieldType::Initials => ! TextFit::fits(self::INITIALS_SAMPLE, $boxWidth),
                default => false,
            };

            if ($tooNarrow) {
                throw $fail(__('Fältet ”:label” är för smalt för :what.', ['label' => trim((string) $definition['label']), 'what' => match ($type) {
                    FieldType::Date => __('ett datum'),
                    FieldType::Initials => __('initialer'),
                    default => __('ett namn'),
                }]));
            }

            foreach ($type === FieldType::Dropdown ? $options : [] as $option) {
                if (! TextFit::fits($option, (int) $definition['width'] / 100)) {
                    throw $fail(__('Alternativet ”:option” får inte plats i fältet.', ['option' => $option]));
                }
            }

            $rules = match ($type) {
                FieldType::Text => ['max_length' => min((int) ($rules['max_length'] ?? self::MAX_TEXT), $fitting)],
                FieldType::Number => array_filter([
                    'min' => isset($rules['min']) ? (string) $rules['min'] : null,
                    'max' => isset($rules['max']) ? (string) $rules['max'] : null,
                    'decimals' => (int) ($rules['decimals'] ?? 0),
                ], fn (mixed $value): bool => $value !== null),
                default => [],
            };

            if ($type === FieldType::Number && isset($rules['min'], $rules['max']) && FieldInput::compare((string) $rules['min'], (string) $rules['max']) > 0) {
                throw $fail(__('Det minsta talet är större än det största.'));
            }

            if ($groupKey !== null) {
                $groups[$groupKey][] = ['index' => $index, 'signer' => (int) $definition['signer'], 'value' => $options[0]];
            }

            $clean[] = [
                'signer' => (int) $definition['signer'],
                'type' => $type,
                'page' => (int) $definition['page'],
                'x' => (int) $definition['x'],
                'y' => (int) $definition['y'],
                'width' => (int) $definition['width'],
                'height' => (int) $definition['height'],
                'label' => trim((string) $definition['label']),
                'required' => (bool) ($definition['required'] ?? false),
                'options' => $options === [] ? null : $options,
                'group_key' => $groupKey,
                'rules' => $rules === [] ? null : $rules,
            ];
        }

        foreach ($groups as $buttons) {
            $values = array_column($buttons, 'value');

            if (count($buttons) > self::MAX_OPTIONS || count(array_unique(array_column($buttons, 'signer'))) !== 1 || count(array_unique($values)) !== count($values)) {
                throw ValidationException::withMessages(["fields.{$buttons[0]['index']}" => __('En grupp av alternativ har högst :max knappar med olika värden, alla för samma undertecknare.', ['max' => self::MAX_OPTIONS])]);
            }
        }

        return $clean;
    }

    /**
     * Places validated fields on the envelope's original PDF, inside the sending transaction and before its first event.
     *
     * @param  list<array<string, mixed>>  $fields  from validate()
     * @param  list<SigningRequest>  $signers  in the order the definitions index them
     * @param  ?array{bytes: string, name: string, sha256: string, fingerprint: string, sizes: list<array{width: float, height: float, rotation: int}>}  $measured  from measure(), of the PDF the envelope was sent with
     *
     * @throws ValidationException
     */
    public function place(SigningEnvelope $envelope, array $signers, array $fields, ?array $measured): int
    {
        if ($fields === []) {
            return 0;
        }

        if ($measured === null || ! hash_equals($measured['sha256'], $envelope->document_sha256)) {
            throw ValidationException::withMessages(['fields' => __('Dokumentet ändrades medan det skickades. Försök igen.')]);
        }

        $sizes = $measured['sizes'];

        foreach ($fields as $position => $field) {
            // Checked by measure() before the quota and the transaction (checkGeometry).
            $size = $sizes[$field['page'] - 1];
            $pageWidth = (int) round($size['width'] * 100);
            $pageHeight = (int) round($size['height'] * 100);

            SigningField::create([
                'envelope_id' => $envelope->id,
                'signing_request_id' => $signers[$field['signer']]->id,
                'type' => $field['type'],
                'page' => $field['page'],
                'x' => $field['x'],
                'y' => $field['y'],
                'width' => $field['width'],
                'height' => $field['height'],
                'page_width' => $pageWidth,
                'page_height' => $pageHeight,
                'label' => $field['label'],
                'required' => $field['required'],
                'options' => $field['options'],
                'group_key' => $field['group_key'],
                'rules' => $field['rules'],
                'position' => $position + 1,
            ]);
        }

        // The page images for the signer page, rendered in the background once the send has committed.
        $original = self::latestVersion($envelope->id);

        if ($original instanceof SigningDocumentVersion) {
            RenderSigningPages::dispatch($envelope->company_id, $original->id)->afterCommit();
        }

        return count($fields);
    }

    /**
     * The path of the finished document of an envelope, for the copies and the company's own file link: the
     * version after the last signer who filled in fields, else the PDF that was sent (an envelope without fields).
     */
    public static function finalVersion(SigningEnvelope $envelope): string
    {
        return SigningDocumentVersion::query()
            ->where('envelope_id', $envelope->id)
            ->where('kind', SigningDocumentVersion::AFTER_SIGNER)
            ->orderByDesc('id')
            ->value('path') ?? $envelope->document_path;
    }

    /** The latest version of an envelope's document that signers are shown: the original, or the one after the last signer. */
    public static function latestVersion(string $envelopeId): ?SigningDocumentVersion
    {
        return SigningDocumentVersion::query()
            ->where('envelope_id', $envelopeId)
            ->whereIn('kind', [SigningDocumentVersion::ORIGINAL, SigningDocumentVersion::AFTER_SIGNER])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The PDF a document will be sent with, made and measured by the engine before the sending transaction and
     * before the mail quota is taken, so a refused send costs neither; the transaction then stores these same
     * bytes (SendForSigning), and only if the document is still what was measured.
     *
     * The fields are checked against the pages here too (checkGeometry), so a field off the page is refused
     * before anything is taken or stored.
     *
     * @param  list<array<string, mixed>>  $fields  from validate()
     * @return array{bytes: string, name: string, sha256: string, fingerprint: string, sizes: list<array{width: float, height: float, rotation: int}>}
     *
     * @throws ValidationException
     */
    public function measure(Signable $document, array $fields): array
    {
        if (! $this->engine->isInstalled()) {
            throw ValidationException::withMessages(['fields' => __('Fält i dokumentet kräver PDF-motorn.')]);
        }

        $fingerprint = $document->signingFingerprint();
        $pdf = $document->signingDocument();
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-signing-'.Str::uuid()->toString().'.pdf';
        file_put_contents($file, $pdf['bytes']);

        try {
            $sizes = $this->engine->inspect($file)->pageSizes;
        } catch (PdfEngineException) {
            throw ValidationException::withMessages(['fields' => __('Dokumentet kunde inte läsas av PDF-motorn, så fält kan inte placeras.')]);
        } finally {
            @unlink($file);
        }

        self::checkGeometry($fields, $sizes);

        return ['bytes' => $pdf['bytes'], 'name' => $pdf['name'], 'sha256' => hash('sha256', $pdf['bytes']), 'fingerprint' => $fingerprint, 'sizes' => $sizes];
    }

    /**
     * Every field on a page that exists, is not rotated, and holds the whole box.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array{width: float, height: float, rotation: int}>  $sizes
     *
     * @throws ValidationException
     */
    public static function checkGeometry(array $fields, array $sizes): void
    {
        foreach ($fields as $position => $field) {
            $size = $sizes[(int) $field['page'] - 1] ?? null;

            if ($size === null) {
                throw ValidationException::withMessages(["fields.{$position}" => __('Dokumentet har inte så många sidor.')]);
            }

            if ($size['rotation'] % 360 !== 0) {
                throw ValidationException::withMessages(["fields.{$position}" => __('Fält kan inte placeras på en roterad sida.')]);
            }

            if ((int) round($size['width'] * 100) < $field['x'] + $field['width'] || (int) round($size['height'] * 100) < $field['y'] + $field['height']) {
                throw ValidationException::withMessages(["fields.{$position}" => __('Fältet hamnar utanför sidan.')]);
            }
        }
    }
}
