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
use Illuminate\Validation\ValidationException;

/**
 * The label templates of a company (ADR-041): the two starting templates,
 * duplicating, choosing the default of a kind and deleting. A kind always
 * keeps one template and exactly one default.
 */
class LabelTemplates
{
    use GuardsInventory;

    /**
     * Creates the starting templates for each kind the company has none of:
     * article labels on an A4 sheet of 3 × 8 and location labels on an A4
     * sheet of 2 × 7, so they print on any office printer. Safe to call
     * again.
     */
    public function ensureDefaults(Company $company, ?User $user = null): void
    {
        CurrentCompany::run($company, function () use ($company, $user): void {
            $existing = LabelTemplate::query()->distinct()->pluck('kind')->map(fn (mixed $kind): string => $kind instanceof LabelKind ? $kind->value : (string) $kind)->all();

            foreach (self::starters() as $kind => $attributes) {
                if (in_array($kind, $existing, true)) {
                    continue;
                }

                $template = LabelTemplate::create([...$attributes, 'kind' => $kind, 'is_default' => true, 'created_by' => $user?->id, 'updated_by' => $user?->id]);
                Audit::record('inventory.label_template_created', $template, ['name' => $template->name, 'kind' => $kind, 'starter' => true], $company, $user);
            }
        });
    }

    public function duplicate(User $user, Company $company, LabelTemplate $template): LabelTemplate
    {
        $this->guard($user, $company, 'settings');

        return CurrentCompany::run($company, fn (): LabelTemplate => DB::transaction(function () use ($user, $company, $template): LabelTemplate {
            $name = $this->freeName(__(':name (kopia)', ['name' => mb_substr($template->name, 0, 90)]));
            $copy = $template->replicate(['is_default', 'created_by', 'updated_by', 'created_at', 'updated_at']);
            $copy->fill(['name' => $name, 'is_default' => false, 'created_by' => $user->id, 'updated_by' => $user->id])->save();
            Audit::record('inventory.label_template_created', $copy, ['name' => $copy->name, 'kind' => $copy->kind->value, 'copy_of' => $template->id], $company, $user);

            return $copy;
        }));
    }

    public function makeDefault(User $user, Company $company, LabelTemplate $template): void
    {
        $this->guard($user, $company, 'settings');

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $template): void {
            LabelTemplate::query()->where('kind', $template->kind->value)->where('is_default', true)->whereKeyNot($template->id)->update(['is_default' => false]);
            $template->update(['is_default' => true, 'updated_by' => $user->id]);
            Audit::record('inventory.label_template_default', $template, ['name' => $template->name, 'kind' => $template->kind->value], $company, $user);
        }));
    }

    /** Deletes a template; the last one of a kind stays. A deleted default passes to the oldest other one. */
    public function delete(User $user, Company $company, LabelTemplate $template): void
    {
        $this->guard($user, $company, 'settings');

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $template): void {
            $others = LabelTemplate::query()->where('kind', $template->kind->value)->whereKeyNot($template->id)->orderBy('id')->lockForUpdate()->get();

            if ($others->isEmpty()) {
                throw ValidationException::withMessages(['template' => __('Det här är den enda mallen för :kind och kan inte tas bort. Skapa en ny först.', ['kind' => mb_strtolower($template->kind->label())])]);
            }

            $wasDefault = $template->is_default;
            Audit::record('inventory.label_template_deleted', $template, ['name' => $template->name, 'kind' => $template->kind->value], $company, $user);
            $template->delete();

            if ($wasDefault) {
                $others->firstOrFail()->update(['is_default' => true]);
            }
        }));
    }

    private function freeName(string $name): string
    {
        $candidate = $name;

        for ($n = 2; LabelTemplate::query()->where('name', $candidate)->exists(); $n++) {
            $candidate = "{$name} {$n}";
        }

        return $candidate;
    }

    /** @return array<string, array<string, mixed>> */
    private static function starters(): array
    {
        $articleSheet = LabelSheets::SHEETS['a4-3x8-70x37'];
        $locationSheet = LabelSheets::SHEETS['a4-2x7-99x38'];

        return [
            LabelKind::Article->value => [
                'name' => __('Prisetikett 70 × 37 mm (A4-ark)'),
                'media' => LabelMedia::Sheet->value,
                'width_mm' => $articleSheet['width'],
                'height_mm' => $articleSheet['height'],
                'padding_mm' => 3.0,
                'sheet_preset' => 'a4-3x8-70x37',
                'columns' => $articleSheet['columns'],
                'rows' => $articleSheet['rows'],
                'sheet_top_mm' => $articleSheet['top'],
                'sheet_left_mm' => $articleSheet['left'],
                'pitch_x_mm' => $articleSheet['pitch_x'],
                'pitch_y_mm' => $articleSheet['pitch_y'],
                'cutting_guides' => false,
                'layout' => LabelLayout::Auto->value,
                'elements' => [LabelElement::Name->value, LabelElement::Price->value, LabelElement::VatNote->value, LabelElement::Barcode->value, LabelElement::Code->value],
            ],
            LabelKind::Location->value => [
                'name' => __('Hyllplats 99 × 38 mm (A4-ark)'),
                'media' => LabelMedia::Sheet->value,
                'width_mm' => $locationSheet['width'],
                'height_mm' => $locationSheet['height'],
                'padding_mm' => 3.0,
                'sheet_preset' => 'a4-2x7-99x38',
                'columns' => $locationSheet['columns'],
                'rows' => $locationSheet['rows'],
                'sheet_top_mm' => $locationSheet['top'],
                'sheet_left_mm' => $locationSheet['left'],
                'pitch_x_mm' => $locationSheet['pitch_x'],
                'pitch_y_mm' => $locationSheet['pitch_y'],
                'cutting_guides' => false,
                'layout' => LabelLayout::Auto->value,
                'elements' => [LabelElement::Code->value, LabelElement::Name->value, LabelElement::Qr->value, LabelElement::Barcode->value],
            ],
        ];
    }
}
