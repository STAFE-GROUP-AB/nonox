<?php

declare(strict_types=1);

namespace App\Modules\Signing\Support;

use App\Domain\Signing\Fields\FieldType;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Fields\TextFit;

/**
 * The geometry of the field editor (ADR-046 amendment, gap 2, package S8):
 * what a field is given when it is added, where the position presets put it,
 * and the clamp that keeps every box inside its page and at least as large
 * as {@see SigningFields::validate()} accepts for its type. The editor, by
 * drag, by keys and by the form, can therefore only produce what the
 * validation accepts.
 *
 * All values are 1/100 PDF points, measured from the lower left corner of
 * the page's crop box, which is the page as PDF.js and the page images show
 * it (the origin question left open by S6). The form speaks millimetres.
 */
final class FieldLayout
{
    /** 1 mm in 1/100 PDF points (72 points per 25.4 mm). */
    private const float UNITS_PER_MM = 72 * 100 / 25.4;

    /** The space kept to the page edge by the position presets: 15 mm. */
    public const int MARGIN = 4252;

    /** The step of a key press, in millimetres, and with Shift held. */
    public const int STEP_MM = 1;

    public const int BIG_STEP_MM = 10;

    /** @var list<string> */
    public const array PRESETS = ['top-left', 'top-center', 'top-right', 'middle-left', 'middle-center', 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right'];

    public static function mmToUnits(float $millimetres): int
    {
        return (int) round($millimetres * self::UNITS_PER_MM);
    }

    public static function unitsToMm(int $units): float
    {
        return round($units / self::UNITS_PER_MM, 1);
    }

    /**
     * The size a new field of a type is given.
     *
     * @return array{0: int, 1: int} width and height
     */
    public static function defaultSize(FieldType $type): array
    {
        return match ($type) {
            FieldType::Signature => [18000, 4500],
            FieldType::Initials => [5000, 3500],
            FieldType::Date => [9000, 2000],
            FieldType::Name, FieldType::Text => [18000, 2000],
            FieldType::Number => [9000, 2000],
            FieldType::Dropdown => [15000, 2000],
            FieldType::Checkbox, FieldType::Radio => [1800, 1800],
        };
    }

    /**
     * The narrowest box a type may have: what NONOX fills in must fit at six
     * points (SigningFields::validate refuses a narrower one), and no box is
     * narrower than the smallest the engine stamps.
     */
    public static function minWidth(FieldType $type): int
    {
        $text = match ($type) {
            FieldType::Date => TextFit::width(SigningFields::DATE_SAMPLE, TextFit::MIN_SIZE),
            FieldType::Initials => TextFit::width(SigningFields::INITIALS_SAMPLE, TextFit::MIN_SIZE),
            FieldType::Name, FieldType::Signature => SigningFields::MIN_NAME_CHARACTERS * 0.62 * TextFit::MIN_SIZE,
            default => 0.0,
        };

        return max(SigningFields::MIN_SIZE, $text === 0.0 ? 0 : (int) ceil(($text + 2 * TextFit::PADDING) * 100) + 1);
    }

    /**
     * Where a preset puts a box of this size on a page.
     *
     * @return array{0: int, 1: int} x and y (lower left corner)
     */
    public static function preset(string $preset, int $width, int $height, int $pageWidth, int $pageHeight): array
    {
        $parts = explode('-', in_array($preset, self::PRESETS, true) ? $preset : 'middle-center');
        $left = self::MARGIN;
        $right = $pageWidth - self::MARGIN - $width;
        $top = $pageHeight - self::MARGIN - $height;
        $bottom = self::MARGIN;

        $x = match ($parts[1]) {
            'left' => $left,
            'right' => $right,
            default => intdiv($pageWidth - $width, 2),
        };

        $y = match ($parts[0]) {
            'top' => $top,
            'bottom' => $bottom,
            default => intdiv($pageHeight - $height, 2),
        };

        return [$x, $y];
    }

    /**
     * A box moved and resized into its page: never smaller than the type
     * allows, never larger than the stamp's bounds or the page, never
     * outside the page.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $box
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function clamp(FieldType $type, array $box, int $pageWidth, int $pageHeight): array
    {
        $minimum = self::minWidth($type);
        $width = max($minimum, min($box['width'], SigningFields::MAX_SIZE, $pageWidth));
        $height = max(SigningFields::MIN_SIZE, min($box['height'], SigningFields::MAX_SIZE, $pageHeight));

        return [
            'x' => max(0, min($box['x'], $pageWidth - $width, SigningFields::MAX_POSITION)),
            'y' => max(0, min($box['y'], $pageHeight - $height, SigningFields::MAX_POSITION)),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * A box resized to a wanted width and height with its upper left corner fixed: the size is held between what the
     * type needs and what the page leaves to the right of and below the corner, so pulling past an edge or below the
     * minimum never moves the opposite edges (the same rule as resizeBox in signing-editor-core.js).
     *
     * @param  array{x: int, y: int, width: int, height: int}  $box
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function resize(FieldType $type, array $box, int $width, int $height, int $pageWidth, int $pageHeight): array
    {
        $top = $box['y'] + $box['height'];
        $newWidth = max(self::minWidth($type), min($width, SigningFields::MAX_SIZE, $pageWidth - $box['x']));
        $newHeight = max(SigningFields::MIN_SIZE, min($height, SigningFields::MAX_SIZE, $top));

        return self::clamp($type, ['x' => $box['x'], 'y' => $top - $newHeight, 'width' => $newWidth, 'height' => $newHeight], $pageWidth, $pageHeight);
    }
}
