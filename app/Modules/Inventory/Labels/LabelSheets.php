<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

/**
 * Label sizes and A4 sheet layouts to start from. The sheet layouts are
 * described only by their dimensions (labels across and down, label size,
 * the margin to the first label and the distance between label origins),
 * so a sheet from any supplier with those measures lines up. "Vanligt
 * A4-papper" centres a grid on plain paper with cutting guides.
 */
final class LabelSheets
{
    public const float PAGE_WIDTH = 210.0;

    public const float PAGE_HEIGHT = 297.0;

    /** Margin kept free on plain paper, where most office printers cannot print. */
    public const float PLAIN_MARGIN = 5.0;

    public const string PLAIN = 'a4-plain';

    public const string CUSTOM = 'custom';

    /** @var array<string, array{0: float, 1: float}> width, height in mm */
    public const array SIZES = [
        '50x25' => [50.0, 25.0],
        '57x32' => [57.0, 32.0],
        '62x29' => [62.0, 29.0],
        '100x50' => [100.0, 50.0],
    ];

    /**
     * Sheet stock with fixed label positions: columns, rows, label width and
     * height, top and left margin to the first label, pitch across and down.
     *
     * @var array<string, array{columns: int, rows: int, width: float, height: float, top: float, left: float, pitch_x: float, pitch_y: float}>
     */
    public const array SHEETS = [
        'a4-3x8-70x37' => ['columns' => 3, 'rows' => 8, 'width' => 70.0, 'height' => 37.0, 'top' => 0.5, 'left' => 0.0, 'pitch_x' => 70.0, 'pitch_y' => 37.0],
        'a4-3x7-70x42' => ['columns' => 3, 'rows' => 7, 'width' => 70.0, 'height' => 42.3, 'top' => 0.45, 'left' => 0.0, 'pitch_x' => 70.0, 'pitch_y' => 42.3],
        'a4-2x7-99x38' => ['columns' => 2, 'rows' => 7, 'width' => 99.1, 'height' => 38.1, 'top' => 15.15, 'left' => 4.65, 'pitch_x' => 101.6, 'pitch_y' => 38.1],
        'a4-4x10-48x25' => ['columns' => 4, 'rows' => 10, 'width' => 48.5, 'height' => 25.4, 'top' => 21.5, 'left' => 8.0, 'pitch_x' => 48.5, 'pitch_y' => 25.4],
        'a4-5x13-38x21' => ['columns' => 5, 'rows' => 13, 'width' => 38.1, 'height' => 21.2, 'top' => 10.7, 'left' => 4.65, 'pitch_x' => 40.6, 'pitch_y' => 21.2],
    ];

    /** @return array<string, string> key => Swedish label */
    public static function sizeOptions(): array
    {
        return [
            '50x25' => __('50 × 25 mm, hyllkant'),
            '57x32' => __('57 × 32 mm'),
            '62x29' => __('62 × 29 mm, vanlig i etikettskrivare'),
            '100x50' => __('100 × 50 mm, pall och låda'),
            self::CUSTOM => __('Egen storlek'),
        ];
    }

    /** @return array<string, string> key => Swedish label */
    public static function sheetOptions(): array
    {
        $options = [self::PLAIN => __('Vanligt A4-papper, rutnät i mitten med stödlinjer för att klippa')];

        foreach (self::SHEETS as $key => $sheet) {
            $options[$key] = __('A4-etikettark, :columns × :rows etiketter à :width × :height mm', [
                'columns' => $sheet['columns'],
                'rows' => $sheet['rows'],
                'width' => self::mm($sheet['width']),
                'height' => self::mm($sheet['height']),
            ]);
        }

        $options[self::CUSTOM] = __('Eget ark: ange marginaler och avstånd');

        return $options;
    }

    /**
     * A grid of labels centred on plain A4, as many as fit inside the
     * printable margin.
     *
     * @return array{columns: int, rows: int, top: float, left: float, pitch_x: float, pitch_y: float}
     */
    public static function plainGrid(float $width, float $height): array
    {
        $usableWidth = self::PAGE_WIDTH - 2 * self::PLAIN_MARGIN;
        $usableHeight = self::PAGE_HEIGHT - 2 * self::PLAIN_MARGIN;
        $columns = max(1, (int) floor($usableWidth / max($width, 1.0)));
        $rows = max(1, (int) floor($usableHeight / max($height, 1.0)));

        return [
            'columns' => $columns,
            'rows' => $rows,
            'top' => round((self::PAGE_HEIGHT - $rows * $height) / 2, 2),
            'left' => round((self::PAGE_WIDTH - $columns * $width) / 2, 2),
            'pitch_x' => $width,
            'pitch_y' => $height,
        ];
    }

    /** A measure in mm the Swedish way: 42,3 or 70. */
    public static function mm(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');

        return $text === '' ? '0' : $text;
    }
}
