<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Actions\LabelTemplates;
use App\Modules\Inventory\Actions\SaveLabelTemplate;
use App\Modules\Inventory\Labels\LabelBox;
use App\Modules\Inventory\Labels\LabelData;
use App\Modules\Inventory\Labels\LabelElement;
use App\Modules\Inventory\Labels\LabelKind;
use App\Modules\Inventory\Labels\LabelLayout;
use App\Modules\Inventory\Labels\LabelLayoutEngine;
use App\Modules\Inventory\Labels\LabelMedia;
use App\Modules\Inventory\Labels\LabelSheets;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\LabelTemplate;
use App\Modules\Inventory\Models\StockLocation;
use App\Support\Branding\Brand;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Lager → Inställningar → Etiketter, the label studio (ADR-041): the
 * company's label templates per kind, and an editor with a live preview at
 * scale. The preview uses the same layout as the PDF and lists in Swedish
 * what will not print well; a grid that does not fit the page is an error
 * and cannot be saved.
 */
class LabelStudioPage extends Component
{
    use InventoryPage;

    public const string NAME = 'inventory.labels';

    /** null: the list; 0: a new template; otherwise the template being edited */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $sizePreset = '50x25';

    public function mount(): void
    {
        abort_unless($this->can('settings'), 403);
        app(LabelTemplates::class)->ensureDefaults($this->company(), $this->user());
    }

    public function create(string $kind = 'ARTICLE'): void
    {
        $kind = LabelKind::tryFrom($kind) ?? LabelKind::Article;
        $this->editing = 0;
        $this->sizePreset = $kind === LabelKind::Location ? '100x50' : '50x25';
        [$width, $height] = LabelSheets::SIZES[$this->sizePreset];
        $this->form = [
            'name' => '',
            'kind' => $kind->value,
            'media' => LabelMedia::Roll->value,
            'width_mm' => (string) $width,
            'height_mm' => (string) $height,
            'padding_mm' => '2',
            'sheet_preset' => LabelSheets::PLAIN,
            'columns' => '3',
            'rows' => '8',
            'sheet_top_mm' => '0',
            'sheet_left_mm' => '0',
            'pitch_x_mm' => (string) $width,
            'pitch_y_mm' => (string) $height,
            'layout' => LabelLayout::Auto->value,
            'elements' => $kind === LabelKind::Location
                ? [LabelElement::Code->value, LabelElement::Name->value, LabelElement::Qr->value, LabelElement::Barcode->value]
                : [LabelElement::Name->value, LabelElement::Price->value, LabelElement::VatNote->value, LabelElement::Barcode->value],
            'property_keys' => [],
            'free_text' => '',
        ];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $template = LabelTemplate::query()->findOrFail($id);
        $this->editing = $template->id;
        $this->form = [
            'name' => $template->name,
            'kind' => $template->kind->value,
            'media' => $template->media->value,
            'width_mm' => LabelSheets::mm($template->width_mm),
            'height_mm' => LabelSheets::mm($template->height_mm),
            'padding_mm' => LabelSheets::mm($template->padding_mm),
            'sheet_preset' => $template->sheet_preset ?? LabelSheets::PLAIN,
            'columns' => (string) $template->columns,
            'rows' => (string) $template->rows,
            'sheet_top_mm' => LabelSheets::mm($template->sheet_top_mm),
            'sheet_left_mm' => LabelSheets::mm($template->sheet_left_mm),
            'pitch_x_mm' => LabelSheets::mm($template->pitch_x_mm),
            'pitch_y_mm' => LabelSheets::mm($template->pitch_y_mm),
            'layout' => $template->layout->value,
            'elements' => $template->elements,
            'property_keys' => $template->property_keys ?? [],
            'free_text' => (string) $template->free_text,
        ];
        $this->sizePreset = $this->presetFor($template->width_mm, $template->height_mm);
        $this->resetErrorBag();
    }

    public function updatedSizePreset(string $preset): void
    {
        if (isset(LabelSheets::SIZES[$preset])) {
            [$width, $height] = LabelSheets::SIZES[$preset];
            $this->form['width_mm'] = (string) $width;
            $this->form['height_mm'] = (string) $height;
        }
    }

    public function cancel(): void
    {
        $this->reset('editing', 'form');
        $this->resetErrorBag();
    }

    public function save(SaveLabelTemplate $save): void
    {
        $template = $this->editing ? LabelTemplate::query()->findOrFail($this->editing) : null;
        $ok = $this->attempt(fn () => $save($this->user(), $this->company(), $this->input(), $template), $template === null ? __('Etikettmallen är skapad.') : __('Etikettmallen är sparad.'));

        if ($ok) {
            $this->cancel();
        }
    }

    public function duplicate(int $id, LabelTemplates $templates): void
    {
        $this->attempt(fn () => $templates->duplicate($this->user(), $this->company(), LabelTemplate::query()->findOrFail($id)), __('Mallen är kopierad.'), '');
    }

    public function makeDefault(int $id, LabelTemplates $templates): void
    {
        $template = LabelTemplate::query()->findOrFail($id);
        $this->attempt(fn () => $templates->makeDefault($this->user(), $this->company(), $template), __(':name är nu standardmall för :kind.', ['name' => $template->name, 'kind' => mb_strtolower($template->kind->label())]), '');
    }

    public function delete(int $id, LabelTemplates $templates): void
    {
        $template = LabelTemplate::query()->findOrFail($id);

        if ($this->attempt(fn () => $templates->delete($this->user(), $this->company(), $template), __('Mallen :name är borttagen.', ['name' => $template->name]), '') && $this->editing === $id) {
            $this->cancel();
        }
    }

    public function render(): View
    {
        $templates = LabelTemplate::query()->orderBy('kind')->orderByDesc('is_default')->orderBy('name')->get();
        $preview = $this->editing !== null ? $this->preview() : null;
        $kind = LabelKind::tryFrom((string) ($this->form['kind'] ?? '')) ?? LabelKind::Article;

        return view('inventory::livewire.label-studio', [
            'groups' => collect(LabelKind::cases())->mapWithKeys(fn (LabelKind $case): array => [$case->value => $templates->where('kind', $case)->values()]),
            'kind' => $kind,
            'elements' => LabelElement::for($kind),
            'layouts' => LabelLayout::cases(),
            'sizes' => LabelSheets::sizeOptions(),
            'sheets' => LabelSheets::sheetOptions(),
            'fixedSheet' => isset(LabelSheets::SHEETS[(string) ($this->form['sheet_preset'] ?? '')]) && ($this->form['media'] ?? '') === LabelMedia::Sheet->value,
            'propertyKeys' => $kind === LabelKind::Article && $this->editing !== null ? $this->propertyKeys() : [],
            'preview' => $preview,
        ]);
    }

    /**
     * The live preview: the template as it would be saved, laid out with the
     * company's first article or location (or example data), the warnings,
     * and the grid on the A4 page. Errors instead when it cannot be saved.
     *
     * @return array{errors: list<string>, template?: LabelTemplate, boxes?: list<LabelBox>, warnings?: list<string>, scale?: float, sample?: string}
     */
    private function preview(): array
    {
        $existing = $this->editing ? LabelTemplate::query()->find($this->editing) : null;

        try {
            $attributes = SaveLabelTemplate::validate($this->company(), [...$this->input(), 'name' => $this->input()['name'] ?: 'förhandsvisning'], $existing);
        } catch (ValidationException $exception) {
            return ['errors' => array_values(array_unique(collect($exception->errors())->flatten()->map(fn (mixed $message): string => (string) $message)->all()))];
        }

        $template = new LabelTemplate([...$attributes, 'kind' => $existing->kind->value ?? $attributes['kind']]);
        $data = $this->sampleData($template);
        $layout = LabelLayoutEngine::layout($template, $data, $template->shows(LabelElement::Logo) ? Brand::for($this->company())->logoDataUri() : null);

        if ($template->shows(LabelElement::Logo) && Brand::for($this->company())->logoDataUri() === null) {
            $layout['warnings'][] = __('Företaget har ingen logotyp. Lägg till den under Företaget, Utseende.');
        }

        return [
            'errors' => [],
            'template' => $template,
            'boxes' => $layout['boxes'],
            'warnings' => $layout['warnings'],
            'scale' => round(min(4.0, 440 / ($template->width_mm * 3.7795)), 3),
            'sample' => $data->id === 0 ? __('Exempeldata') : $data->name,
        ];
    }

    private function sampleData(LabelTemplate $template): LabelData
    {
        if ($template->kind === LabelKind::Location) {
            $location = StockLocation::query()->where('is_active', true)->orderByRaw('length(full_code) desc')->orderBy('sort_key')->first();

            return $location === null ? LabelData::sample(LabelKind::Location) : LabelData::forLocation($location);
        }

        $article = Article::query()->where('is_active', true)->where('is_stock_item', true)->orderBy('number')->first();

        return $article === null ? LabelData::sample(LabelKind::Article) : LabelData::forArticle($article, $this->can('printPrices'), $template->property_keys ?? []);
    }

    /**
     * The property names in use on the company's articles (at most 50), to
     * choose from. One query.
     *
     * @return list<string>
     */
    private function propertyKeys(): array
    {
        return array_values(Article::query()
            ->whereNotNull('properties')
            ->selectRaw("distinct jsonb_array_elements(properties)->>'key' as property_key")
            ->limit(50)
            ->pluck('property_key')
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->map(fn (mixed $key): string => (string) $key)
            ->sort()
            ->all());
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $decimal = fn (mixed $value): string => str_replace([',', ' '], ['.', ''], trim((string) $value));
        $form = $this->form;

        foreach (['width_mm', 'height_mm', 'padding_mm', 'sheet_top_mm', 'sheet_left_mm', 'pitch_x_mm', 'pitch_y_mm'] as $key) {
            $form[$key] = $decimal($form[$key] ?? '');
        }

        return $form;
    }

    private function presetFor(float $width, float $height): string
    {
        foreach (LabelSheets::SIZES as $key => [$presetWidth, $presetHeight]) {
            if (abs($presetWidth - $width) < 0.01 && abs($presetHeight - $height) < 0.01) {
                return $key;
            }
        }

        return LabelSheets::CUSTOM;
    }
}
