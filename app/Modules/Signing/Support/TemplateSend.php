<?php

declare(strict_types=1);

namespace App\Modules\Signing\Support;

use App\Models\SigningField;
use App\Modules\Signing\Models\SigningTemplate;
use Carbon\CarbonImmutable;

/**
 * What a template gives an envelope when it is sent from it (ADR-046 S14,
 * S15, S19), in the shape SendForSigning::envelope reads, in one place so
 * that a bulk send and the API send make the same envelope: the template's
 * fields (the signer is the index of the role), the last day counted from
 * today, the reminder settings and the copy recipients.
 */
final class TemplateSend
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function fields(SigningTemplate $template): array
    {
        $indexOf = [];

        foreach ($template->roles->values() as $index => $role) {
            $indexOf[$role->key] = $index;
        }

        return $template->fields->map(fn (SigningField $field): array => [
            'signer' => $indexOf[(string) $field->role_key] ?? 0,
            'type' => $field->type->value,
            'page' => $field->page,
            'x' => $field->x,
            'y' => $field->y,
            'width' => $field->width,
            'height' => $field->height,
            'label' => $field->label,
            'required' => $field->required,
            'options' => (array) ($field->options ?? []),
            'group_key' => $field->group_key,
            'rules' => (array) ($field->rules ?? []),
        ])->values()->all();
    }

    public static function expiresOn(SigningTemplate $template): ?string
    {
        return $template->deadline_days === null ? null : CarbonImmutable::now((string) config('app.timezone'))->addDays($template->deadline_days)->format('Y-m-d');
    }

    /** @return array{auto_reminders: bool, reminder_days: string} */
    public static function reminders(SigningTemplate $template): array
    {
        return ['auto_reminders' => $template->auto_reminders, 'reminder_days' => $template->reminder_days === null ? 'single' : (string) $template->reminder_days];
    }
}
