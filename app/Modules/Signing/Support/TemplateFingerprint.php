<?php

declare(strict_types=1);

namespace App\Modules\Signing\Support;

use App\Models\SigningField;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\Models\SigningTemplateRole;

/**
 * A SHA-256 of what a bulk send takes from a template (ADR-046 S15, principle
 * 9): the file, the roles with their steps and checks, the fields and the
 * defaults. Taken at the preview and compared at the confirmation and while
 * the run goes, so that a person never sends something other than what they
 * checked.
 */
final class TemplateFingerprint
{
    public static function of(SigningTemplate $template): string
    {
        // Always from the stored row, so that a template just created in this request and one read later give the same value.
        $template = SigningTemplate::query()->findOrFail($template->id);
        $roles = SigningTemplateRole::query()->where('template_id', $template->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (SigningTemplateRole $role): array => [$role->key, $role->label, $role->step, $role->verification, $role->internal, $role->position])->all();

        $fields = SigningField::query()->where('template_id', $template->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (SigningField $field): array => [$field->role_key, $field->type->value, $field->page, $field->x, $field->y, $field->width, $field->height, $field->label, $field->required, $field->options, $field->group_key, $field->rules])->all();

        return hash('sha256', (string) json_encode([
            $template->sha256, $template->document_type, $roles, $fields,
            [$template->deadline_days, $template->auto_reminders, $template->reminder_days, $template->language, $template->confidential, $template->copies],
        ], JSON_UNESCAPED_UNICODE));
    }
}
