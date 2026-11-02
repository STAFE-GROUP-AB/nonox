<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Labels\LabelElement;
use App\Modules\Inventory\Labels\LabelKind;
use App\Modules\Inventory\Labels\LabelLayout;
use App\Modules\Inventory\Labels\LabelMedia;
use App\Modules\Inventory\Labels\LabelSheets;
use App\Modules\Inventory\Models\LabelTemplate;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a label template (ADR-041). The kind is chosen once.
 * On an A4 sheet the grid comes from a sheet layout (its fixed measures), is
 * centred on plain paper, or is entered by hand; a grid that runs off the
 * page or labels that overlap are refused. The first template of a kind
 * becomes its default.
 */
class SaveLabelTemplate
{
    use GuardsInventory;

    /**
     * @param  array<string, mixed>  $input  name, kind, media, width_mm, height_mm, padding_mm, sheet_preset, columns, rows, sheet_top_mm, sheet_left_mm, pitch_x_mm, pitch_y_mm, layout, elements, property_keys, free_text
     */
    public function __invoke(User $user, Company $company, array $input, ?LabelTemplate $template = null): LabelTemplate
    {
        $this->guard($user, $company, 'settings');

        $attributes = self::validate($company, $input, $template);

        return CurrentCompany::run($company, fn (): LabelTemplate => DB::transaction(function () use ($attributes, $template, $company, $user): LabelTemplate {
            if ($template === null) {
                $kind = (string) $attributes['kind'];
                $template = LabelTemplate::create([
                    ...$attributes,
                    'is_default' => ! LabelTemplate::query()->where('kind', $kind)->where('is_default', true)->exists(),
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
                Audit::record('inventory.label_template_created', $template, ['name' => $template->name, 'kind' => $kind], $company, $user);

                return $template;
            }

            unset($attributes['kind']);
            $template->update([...$attributes, 'updated_by' => $user->id]);
            Audit::record('inventory.label_template_updated', $template, ['name' => $template->name], $company, $user);

            return $template;
        }));
    }

    /**
     * The checked attributes, with the sheet grid resolved. Also used by the
     * studio for its live preview, so a preview never shows a grid that
     * cannot be saved.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(Company $company, array $input, ?LabelTemplate $template = null): array
    {
        $kind = $template?->kind->value ?? ($input['kind'] ?? null);
        $allowed = LabelKind::tryFrom((string) $kind) === null ? [] : array_map(fn (LabelElement $element): string => $element->value, LabelElement::for(LabelKind::from((string) $kind)));

        $validated = Validator::make([...$input, 'kind' => $kind], [
            'name' => ['required', 'string', 'max:100', Rule::unique('label_templates', 'name')->where('company_id', $company->id)->ignore($template?->id)],
            'kind' => ['required', Rule::enum(LabelKind::class)],
            'media' => ['required', Rule::enum(LabelMedia::class)],
            'width_mm' => ['required', 'numeric', 'min:15', 'max:200'],
            'height_mm' => ['required', 'numeric', 'min:10', 'max:290'],
            'padding_mm' => ['required', 'numeric', 'min:0', 'max:15'],
            'sheet_preset' => ['nullable', Rule::in([LabelSheets::PLAIN, LabelSheets::CUSTOM, ...array_keys(LabelSheets::SHEETS)])],
            'columns' => ['nullable', 'integer', 'min:1', 'max:20'],
            'rows' => ['nullable', 'integer', 'min:1', 'max:40'],
            'sheet_top_mm' => ['nullable', 'numeric', 'min:0', 'max:150'],
            'sheet_left_mm' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pitch_x_mm' => ['nullable', 'numeric', 'min:0', 'max:210'],
            'pitch_y_mm' => ['nullable', 'numeric', 'min:0', 'max:297'],
            'layout' => ['required', Rule::enum(LabelLayout::class)],
            'elements' => ['required', 'array', 'min:1'],
            'elements.*' => ['string', Rule::in($allowed)],
            'property_keys' => ['nullable', 'array', 'max:10'],
            'property_keys.*' => ['string', 'max:60'],
            'free_text' => ['nullable', 'string', 'max:120'],
        ], [
            'elements.required' => __('Välj minst en sak som ska synas på etiketten.'),
            'elements.min' => __('Välj minst en sak som ska synas på etiketten.'),
            'elements.*.in' => __('Det valda innehållet finns inte för den här sortens etikett.'),
        ], [
            'name' => __('namn'),
            'width_mm' => __('bredd'),
            'height_mm' => __('höjd'),
            'padding_mm' => __('marginal'),
            'columns' => __('kolumner'),
            'rows' => __('rader'),
            'sheet_top_mm' => __('övre marginal'),
            'sheet_left_mm' => __('vänster marginal'),
            'pitch_x_mm' => __('avstånd i sidled'),
            'pitch_y_mm' => __('avstånd i höjdled'),
        ])->validate();

        $media = LabelMedia::from((string) $validated['media']);
        $width = round((float) $validated['width_mm'], 2);
        $height = round((float) $validated['height_mm'], 2);
        $padding = round((float) $validated['padding_mm'], 2);

        if (2 * $padding >= min($width, $height)) {
            throw ValidationException::withMessages(['padding_mm' => __('Marginalen är större än halva etiketten. Minska den.')]);
        }

        $elements = array_values(array_intersect($allowed, array_map('strval', (array) $validated['elements'])));
        $grid = ['sheet_preset' => null, 'columns' => 1, 'rows' => 1, 'sheet_top_mm' => 0.0, 'sheet_left_mm' => 0.0, 'pitch_x_mm' => $width, 'pitch_y_mm' => $height, 'cutting_guides' => false];

        if ($media === LabelMedia::Sheet) {
            $grid = self::grid($validated, $width, $height);
            $width = $grid['width'];
            $height = $grid['height'];
            unset($grid['width'], $grid['height']);
        }

        return [
            'name' => trim((string) $validated['name']),
            'kind' => (string) $validated['kind'],
            'media' => $media->value,
            'width_mm' => $width,
            'height_mm' => $height,
            'padding_mm' => $padding,
            ...$grid,
            'layout' => (string) $validated['layout'],
            'elements' => $elements,
            'property_keys' => array_values(array_unique(array_filter(array_map(fn (mixed $key): string => trim((string) $key), (array) ($validated['property_keys'] ?? []))))),
            'free_text' => trim((string) ($validated['free_text'] ?? '')) ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{sheet_preset: string, columns: int, rows: int, sheet_top_mm: float, sheet_left_mm: float, pitch_x_mm: float, pitch_y_mm: float, cutting_guides: bool, width: float, height: float}
     */
    private static function grid(array $validated, float $width, float $height): array
    {
        $preset = (string) ($validated['sheet_preset'] ?? LabelSheets::PLAIN) ?: LabelSheets::PLAIN;

        if (isset(LabelSheets::SHEETS[$preset])) {
            $sheet = LabelSheets::SHEETS[$preset];

            return ['sheet_preset' => $preset, 'columns' => $sheet['columns'], 'rows' => $sheet['rows'], 'sheet_top_mm' => $sheet['top'], 'sheet_left_mm' => $sheet['left'], 'pitch_x_mm' => $sheet['pitch_x'], 'pitch_y_mm' => $sheet['pitch_y'], 'cutting_guides' => false, 'width' => $sheet['width'], 'height' => $sheet['height']];
        }

        if ($preset === LabelSheets::PLAIN) {
            if ($width > LabelSheets::PAGE_WIDTH - 2 * LabelSheets::PLAIN_MARGIN || $height > LabelSheets::PAGE_HEIGHT - 2 * LabelSheets::PLAIN_MARGIN) {
                throw ValidationException::withMessages(['width_mm' => __('Etiketten är större än ett A4-papper. Välj en mindre storlek.')]);
            }

            $plain = LabelSheets::plainGrid($width, $height);

            return ['sheet_preset' => $preset, 'columns' => $plain['columns'], 'rows' => $plain['rows'], 'sheet_top_mm' => $plain['top'], 'sheet_left_mm' => $plain['left'], 'pitch_x_mm' => $plain['pitch_x'], 'pitch_y_mm' => $plain['pitch_y'], 'cutting_guides' => true, 'width' => $width, 'height' => $height];
        }

        $columns = (int) ($validated['columns'] ?? 1);
        $rows = (int) ($validated['rows'] ?? 1);
        $top = round((float) ($validated['sheet_top_mm'] ?? 0), 2);
        $left = round((float) ($validated['sheet_left_mm'] ?? 0), 2);
        $pitchX = round((float) ($validated['pitch_x_mm'] ?? $width), 2);
        $pitchY = round((float) ($validated['pitch_y_mm'] ?? $height), 2);
        $errors = [];

        if ($columns > 1 && $pitchX + 0.001 < $width) {
            $errors['pitch_x_mm'] = __('Avståndet i sidled är mindre än etikettens bredd, så etiketterna skulle överlappa.');
        }

        if ($rows > 1 && $pitchY + 0.001 < $height) {
            $errors['pitch_y_mm'] = __('Avståndet i höjdled är mindre än etikettens höjd, så etiketterna skulle överlappa.');
        }

        if ($left + ($columns - 1) * $pitchX + $width > LabelSheets::PAGE_WIDTH + 0.01) {
            $errors['columns'] = __('Rutnätet blir :size mm brett men A4 är 210 mm. Minska kolumnerna, marginalen eller avståndet.', ['size' => LabelSheets::mm(round($left + ($columns - 1) * $pitchX + $width, 2))]);
        }

        if ($top + ($rows - 1) * $pitchY + $height > LabelSheets::PAGE_HEIGHT + 0.01) {
            $errors['rows'] = __('Rutnätet blir :size mm högt men A4 är 297 mm. Minska raderna, marginalen eller avståndet.', ['size' => LabelSheets::mm(round($top + ($rows - 1) * $pitchY + $height, 2))]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['sheet_preset' => LabelSheets::CUSTOM, 'columns' => $columns, 'rows' => $rows, 'sheet_top_mm' => $top, 'sheet_left_mm' => $left, 'pitch_x_mm' => $pitchX, 'pitch_y_mm' => $pitchY, 'cutting_guides' => false, 'width' => $width, 'height' => $height];
    }
}
