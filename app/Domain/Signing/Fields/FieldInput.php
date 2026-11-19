<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Signing\SignatureImage;
use App\Models\SigningField;
use App\Models\SigningRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What a signer filled in, checked on the server against each field's type
 * and rules (ADR-046 amendment, gap 2, package S6). Only the signer's own
 * fields are read: anything posted for another field is left out and
 * reported (FIELD_REJECTED), never written.
 *
 * - A signature takes the drawn signature, or the typed name.
 * - Initials take the drawn initials, or typed initials (1–10 letters).
 * - A date is the signing date in Stockholm time (ÅÅÅÅ-MM-DD), a name the
 *   name the signer gives: neither can be typed in the field.
 * - A text has at most its max_length (≤ 500) characters, no control
 *   characters; a number its decimals, min and max (a decimal comma is
 *   read); a checkbox is ticked or not; a dropdown value and a radio choice
 *   must be one of the options.
 */
final class FieldInput
{
    public const int MAX_INITIALS = 10;

    /**
     * Control and format characters are refused, the bidirectional overrides and isolates (U+202A–202E,
     * U+2066–2069) among them, except the zero-width joiner U+200D that emoji sequences need (U+FE0F, the
     * emoji variation selector, is a mark and not refused).
     */
    public const string REFUSED_CHARACTERS = '/(?!\x{200D})\p{C}/u';

    /** Emoji, pictographs and regional indicators: not in the stamp's fonts, so refused in what is stamped. */
    public const string EMOJI = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{1F1E6}-\x{1F1FF}\x{FE0F}]/u';

    /**
     * @param  Collection<int, SigningField>  $own  the signer's fields
     * @param  array<string, mixed>  $input  fields (id => value), radio (group => field id), initials_method, initials, typed_initials
     * @param  array{method: string, image: ?string}  $signature  the signer's signature as SignAtLink checked it
     * @return list<array{field: SigningField, value: ?string, image: ?string}>
     *
     * @throws ValidationException
     */
    public static function values(Collection $own, array $input, string $name, array $signature, CarbonImmutable $signedAt): array
    {
        $posted = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $radio = is_array($input['radio'] ?? null) ? $input['radio'] : [];
        $initials = $own->contains(fn (SigningField $field): bool => $field->type === FieldType::Initials) ? self::initials($input, $own) : null;
        $errors = [];
        $values = [];

        foreach ($own as $field) {
            $raw = $posted[(string) $field->id] ?? $posted[$field->id] ?? null;
            $raw = is_scalar($raw) ? (string) $raw : null;

            try {
                $value = match ($field->type) {
                    FieldType::Signature => $signature['method'] === SigningRequest::DRAWN ? ['value' => null, 'image' => $signature['image']] : ['value' => $name, 'image' => null],
                    FieldType::Initials => $initials,
                    FieldType::Date => ['value' => $signedAt->setTimezone('Europe/Stockholm')->format('Y-m-d'), 'image' => null],
                    FieldType::Name => ['value' => $name, 'image' => null],
                    FieldType::Text => self::text($field, $raw),
                    FieldType::Number => self::number($field, $raw),
                    FieldType::Checkbox => self::checkbox($field, $raw),
                    FieldType::Dropdown => self::dropdown($field, $raw),
                    FieldType::Radio => self::radio($field, $own, $radio),
                };
            } catch (FieldRefused $refused) {
                $errors["fields.{$field->id}"] = $refused->getMessage();

                continue;
            }

            // The stamp's fonts (Noto Sans, Noto Sans CJK) have no emoji: they would print as empty boxes.
            if ($value !== null && $value['value'] !== null && preg_match(self::EMOJI, $value['value']) === 1) {
                $errors["fields.{$field->id}"] = (string) __('Emoji och liknande symboler kan inte skrivas i dokumentet.');

                continue;
            }

            // Text the signer typed never runs outside its box: shrunk down to 6 points, and refused when it is still
            // too wide. A date, a name and a typed signature are not refused (the box was checked when it was placed);
            // the engine shrinks them to fit and clips them to the box.
            if ($value !== null && $value['value'] !== null && in_array($field->type, [FieldType::Text, FieldType::Number, FieldType::Dropdown], true)
                && ! TextFit::fits($field->type === FieldType::Number ? self::displayNumber($value['value']) : $value['value'], $field->width / 100)) {
                $errors["fields.{$field->id}"] = (string) __('Texten får inte plats i fältet');

                continue;
            }

            if ($value !== null) {
                $values[] = ['field' => $field, 'value' => $value['value'], 'image' => $value['image']];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * The ids posted for fields that are not the signer's own (another signer's, another envelope's, or none).
     *
     * @param  Collection<int, SigningField>  $own
     * @param  Collection<int, SigningField>  $envelopeFields
     * @param  array<string, mixed>  $input
     * @return array{field_ids: list<int>, other: int}
     */
    public static function rejected(Collection $own, Collection $envelopeFields, array $input): array
    {
        $ownIds = $own->pluck('id')->all();
        $envelopeIds = $envelopeFields->pluck('id')->all();
        $ownGroups = $own->pluck('group_key')->filter()->unique()->all();
        $ids = [];
        $other = 0;

        foreach (array_keys(is_array($input['fields'] ?? null) ? $input['fields'] : []) as $key) {
            $id = filter_var($key, FILTER_VALIDATE_INT);

            if ($id !== false && in_array($id, $ownIds, true)) {
                continue;
            }

            $id !== false && in_array($id, $envelopeIds, true) ? $ids[] = $id : $other++;
        }

        foreach (is_array($input['radio'] ?? null) ? $input['radio'] : [] as $group => $chosen) {
            if (in_array((string) $group, $ownGroups, true)) {
                continue;
            }

            $id = filter_var($chosen, FILTER_VALIDATE_INT);
            $id !== false && in_array($id, $envelopeIds, true) ? $ids[] = $id : $other++;
        }

        sort($ids);

        return ['field_ids' => array_slice(array_values(array_unique($ids)), 0, 50), 'other' => $other];
    }

    /** Compares two decimal numbers exactly (no floats), as bccomp does; anything that is not a number sorts as zero. */
    public static function compare(string $left, string $right): int
    {
        return bccomp(is_numeric($left) ? $left : '0', is_numeric($right) ? $right : '0', SigningFields::MAX_DECIMALS);
    }

    /** A number as stored (a point for the decimals) shown the Swedish way. */
    public static function displayNumber(string $value): string
    {
        return str_replace('.', ',', $value);
    }

    /**
     * @param  Collection<int, SigningField>  $own
     * @param  array<string, mixed>  $input
     * @return ?array{value: ?string, image: ?string}
     *
     * @throws ValidationException
     */
    private static function initials(array $input, Collection $own): ?array
    {
        // TODO(legal-verify): ADR-046 gap 2 — whether initials on each page add evidential value in Swedish practice, or should be described only as a convenience.
        $required = $own->contains(fn (SigningField $field): bool => $field->type === FieldType::Initials && $field->required);
        $method = (string) ($input['initials_method'] ?? SigningRequest::DRAWN);
        $typed = trim(preg_replace('/\s+/u', ' ', (string) ($input['typed_initials'] ?? '')) ?? '');
        $drawn = (string) ($input['initials'] ?? '');

        // Without JavaScript nothing can be drawn: typed initials count even when "Rita initialerna" is still chosen.
        if ($method === SigningRequest::TYPED || ($drawn === '' && $typed !== '')) {
            if ($typed === '') {
                return $required ? throw ValidationException::withMessages(['initials' => __('Skriv dina initialer, eller rita dem i rutan.')]) : null;
            }

            if (mb_strlen($typed) > self::MAX_INITIALS || preg_match('/^\p{L}[\p{L}.\- ]*$/u', $typed) !== 1) {
                throw ValidationException::withMessages(['initials' => __('Initialerna får vara högst :max bokstäver.', ['max' => self::MAX_INITIALS])]);
            }

            return ['value' => $typed, 'image' => null];
        }

        if ($drawn === '') {
            return $required ? throw ValidationException::withMessages(['initials' => __('Rita dina initialer i rutan, eller välj att skriva dem.')]) : null;
        }

        try {
            return ['value' => null, 'image' => SignatureImage::base64($drawn)];
        } catch (ValidationException) {
            throw ValidationException::withMessages(['initials' => __('Rita dina initialer i rutan, eller välj att skriva dem.')]);
        }
    }

    /** @return ?array{value: ?string, image: ?string} */
    private static function text(SigningField $field, ?string $raw): ?array
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return $field->required ? throw new FieldRefused(__('Fyll i ”:label”.', ['label' => $field->label])) : null;
        }

        if (! mb_check_encoding($value, 'UTF-8') || preg_match(self::REFUSED_CHARACTERS, $value) === 1) {
            throw new FieldRefused(__('”:label” innehåller tecken som inte går att använda.', ['label' => $field->label]));
        }

        $max = min($field->rule('max_length') ?? SigningFields::MAX_TEXT, SigningFields::MAX_TEXT);

        if (mb_strlen($value) > $max) {
            throw new FieldRefused(__('”:label” får vara högst :max tecken.', ['label' => $field->label, 'max' => $max]));
        }

        return ['value' => $value, 'image' => null];
    }

    /** @return ?array{value: ?string, image: ?string} */
    private static function number(SigningField $field, ?string $raw): ?array
    {
        $value = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $raw));

        if ($value === '') {
            return $field->required ? throw new FieldRefused(__('Fyll i ”:label”.', ['label' => $field->label])) : null;
        }

        if (preg_match('/^-?\d{1,15}(\.\d+)?$/', $value) !== 1) {
            throw new FieldRefused(__('”:label” ska vara ett tal.', ['label' => $field->label]));
        }

        $decimals = $field->rule('decimals') ?? 0;
        $given = str_contains($value, '.') ? strlen(substr($value, strpos($value, '.') + 1)) : 0;

        if ($given > $decimals) {
            throw new FieldRefused($decimals === 0
                ? __('”:label” ska vara ett heltal.', ['label' => $field->label])
                : __('”:label” får ha högst :decimals decimaler.', ['label' => $field->label, 'decimals' => $decimals]));
        }

        $min = isset($field->rules['min']) ? (string) $field->rules['min'] : null;
        $max = isset($field->rules['max']) ? (string) $field->rules['max'] : null;

        if (($min !== null && self::compare($value, $min) < 0) || ($max !== null && self::compare($value, $max) > 0)) {
            throw new FieldRefused(match (true) {
                $min !== null && $max !== null => __('”:label” ska vara mellan :min och :max.', ['label' => $field->label, 'min' => self::displayNumber($min), 'max' => self::displayNumber($max)]),
                $min !== null => __('”:label” ska vara minst :min.', ['label' => $field->label, 'min' => self::displayNumber($min)]),
                default => __('”:label” får vara högst :max.', ['label' => $field->label, 'max' => self::displayNumber((string) $max)]),
            });
        }

        // "-0" and "007" are kept as one canonical form, so the stamp and the hash read the same.
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, null);
        $whole = ltrim((string) $whole, '0') === '' ? '0' : ltrim((string) $whole, '0');
        $canonical = $whole.($fraction === null || $fraction === '' ? '' : ".{$fraction}");

        return ['value' => ($negative && trim($canonical, '0.') !== '' ? '-' : '').$canonical, 'image' => null];
    }

    /** @return ?array{value: ?string, image: ?string} */
    private static function checkbox(SigningField $field, ?string $raw): ?array
    {
        if ($raw === '1') {
            return ['value' => '1', 'image' => null];
        }

        if ($raw !== null && $raw !== '' && $raw !== '0') {
            throw new FieldRefused(__('”:label” kan bara kryssas i eller lämnas tom.', ['label' => $field->label]));
        }

        return $field->required ? throw new FieldRefused(__('Kryssa i ”:label”.', ['label' => $field->label])) : null;
    }

    /** @return ?array{value: ?string, image: ?string} */
    private static function dropdown(SigningField $field, ?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return $field->required ? throw new FieldRefused(__('Välj i ”:label”.', ['label' => $field->label])) : null;
        }

        if (! in_array($raw, $field->options ?? [], true)) {
            throw new FieldRefused(__('Välj ett av alternativen i ”:label”.', ['label' => $field->label]));
        }

        return ['value' => $raw, 'image' => null];
    }

    /**
     * A radio button gets its value when it is the group's choice; the other buttons of the group get none.
     *
     * @param  Collection<int, SigningField>  $own
     * @param  array<mixed>  $radio
     * @return ?array{value: ?string, image: ?string}
     */
    private static function radio(SigningField $field, Collection $own, array $radio): ?array
    {
        $group = $own->filter(fn (SigningField $button): bool => $button->type === FieldType::Radio && $button->group_key === $field->group_key);
        // The group is checked once, on its first button.
        $first = $group->sortBy('position')->first()?->id === $field->id;
        $posted = $radio[(string) $field->group_key] ?? null;
        $chosen = is_scalar($posted) && $posted !== '' ? filter_var($posted, FILTER_VALIDATE_INT) : null;

        if ($chosen === null) {
            return $first && $group->contains(fn (SigningField $button): bool => $button->required)
                ? throw new FieldRefused(__('Välj ett alternativ i ”:label”.', ['label' => $field->label]))
                : null;
        }

        if ($chosen === false || ! $group->contains('id', $chosen)) {
            return $first ? throw new FieldRefused(__('Välj ett av alternativen i ”:label”.', ['label' => $field->label])) : null;
        }

        return $chosen === $field->id ? ['value' => $field->radioValue(), 'image' => null] : null;
    }
}
