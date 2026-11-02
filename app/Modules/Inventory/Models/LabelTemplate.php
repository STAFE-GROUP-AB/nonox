<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Labels\LabelElement;
use App\Modules\Inventory\Labels\LabelKind;
use App\Modules\Inventory\Labels\LabelLayout;
use App\Modules\Inventory\Labels\LabelMedia;
use App\Modules\Inventory\Labels\LabelSheets;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A label template (ADR-041): the label size, how labels sit on the page
 * (an A4 sheet grid or one per page on a roll), the layout and the elements
 * shown. One default per company and kind.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property LabelKind $kind
 * @property LabelMedia $media
 * @property float $width_mm
 * @property float $height_mm
 * @property float $padding_mm
 * @property ?string $sheet_preset
 * @property int $columns
 * @property int $rows
 * @property float $sheet_top_mm
 * @property float $sheet_left_mm
 * @property float $pitch_x_mm
 * @property float $pitch_y_mm
 * @property bool $cutting_guides
 * @property LabelLayout $layout
 * @property list<string> $elements
 * @property ?list<string> $property_keys
 * @property ?string $free_text
 * @property bool $is_default
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class LabelTemplate extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'kind',
        'media',
        'width_mm',
        'height_mm',
        'padding_mm',
        'sheet_preset',
        'columns',
        'rows',
        'sheet_top_mm',
        'sheet_left_mm',
        'pitch_x_mm',
        'pitch_y_mm',
        'cutting_guides',
        'layout',
        'elements',
        'property_keys',
        'free_text',
        'is_default',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => LabelKind::class,
            'media' => LabelMedia::class,
            'layout' => LabelLayout::class,
            'width_mm' => 'float',
            'height_mm' => 'float',
            'padding_mm' => 'float',
            'columns' => 'integer',
            'rows' => 'integer',
            'sheet_top_mm' => 'float',
            'sheet_left_mm' => 'float',
            'pitch_x_mm' => 'float',
            'pitch_y_mm' => 'float',
            'cutting_guides' => 'boolean',
            'elements' => 'array',
            'property_keys' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function shows(LabelElement $element): bool
    {
        return in_array($element->value, $this->elements, true);
    }

    /** Labels on one page: the grid on a sheet, one on a roll. */
    public function perPage(): int
    {
        return $this->media === LabelMedia::Roll ? 1 : max(1, $this->columns * $this->rows);
    }

    /** @return array{0: float, 1: float} page width and height in mm */
    public function pageSize(): array
    {
        return $this->media === LabelMedia::Roll
            ? [$this->width_mm, $this->height_mm]
            : [LabelSheets::PAGE_WIDTH, LabelSheets::PAGE_HEIGHT];
    }

    /**
     * The top-left corner of the label at a position on the page (0-based,
     * row by row).
     *
     * @return array{0: float, 1: float} x, y in mm
     */
    public function origin(int $slot): array
    {
        if ($this->media === LabelMedia::Roll) {
            return [0.0, 0.0];
        }

        $columns = max(1, $this->columns);

        return [
            round($this->sheet_left_mm + ($slot % $columns) * $this->pitch_x_mm, 3),
            round($this->sheet_top_mm + intdiv($slot, $columns) * $this->pitch_y_mm, 3),
        ];
    }

    /** "50 × 25 mm, A4-ark 4 × 10" for lists. */
    public function summary(): string
    {
        $size = LabelSheets::mm($this->width_mm).' × '.LabelSheets::mm($this->height_mm).' mm';

        return $this->media === LabelMedia::Roll
            ? __(':size, etikettskrivare', ['size' => $size])
            : __(':size, A4-ark :columns × :rows', ['size' => $size, 'columns' => $this->columns, 'rows' => $this->rows]);
    }
}
