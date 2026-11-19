<?php

declare(strict_types=1);

namespace App\Modules\Signing\Support;

use App\Domain\Signing\Fields\FieldType;
use App\Models\SigningField;
use App\Modules\Signing\Models\SigningTemplate;
use Carbon\CarbonImmutable;

/**
 * What a signing template puts into the send wizard (ADR-046 S14): one
 * signer row per role (the role's name, step and check), the fields placed
 * for the roles in the shape the field editor holds them, and the template's
 * defaults. The addresses stay empty: a template names roles, never people.
 */
final class TemplateWizardState
{
    /**
     * @param  bool  $withFields  only when the document is the template's own file (same SHA-256)
     * @return array{
     *     signers: list<array{email: string, step: int, verification: string, access_code: string, language: string, role: string, role_key: string}>,
     *     fields: list<array<string, mixed>>,
     *     copies: list<array{email: string, name: string, language: string}>,
     *     expiresOn: string,
     *     autoReminders: bool,
     *     reminderDays: string,
     *     confidential: bool,
     *     internal: list<int>
     * }
     */
    public static function for(SigningTemplate $template, bool $withFields, bool $mayBeConfidential): array
    {
        $roles = $template->roles()->get();
        $indexOf = [];
        $signers = [];
        $internal = [];

        foreach ($roles->values() as $index => $role) {
            $indexOf[$role->key] = $index;
            $signers[] = [
                'email' => '',
                'step' => $role->step,
                'verification' => $role->verification,
                'access_code' => '',
                'language' => $template->language,
                'role' => $role->label,
                'role_key' => $role->key,
            ];

            if ($role->internal) {
                $internal[] = $index;
            }
        }

        $fields = [];

        if ($withFields && $template->hasFile()) {
            foreach ($template->fields()->get() as $field) {
                $fields[] = self::field($field, $indexOf[(string) $field->role_key] ?? 0);
            }
        }

        $days = $template->deadline_days;

        return [
            'signers' => $signers,
            'fields' => $fields,
            'copies' => array_map(fn (array $copy): array => ['email' => (string) $copy['email'], 'name' => (string) ($copy['name'] ?? ''), 'language' => (string) $copy['language']], (array) ($template->copies ?? [])),
            'expiresOn' => $days === null ? '' : CarbonImmutable::now(config('app.timezone'))->addDays($days)->format('Y-m-d'),
            'autoReminders' => $template->auto_reminders,
            // No interval is the one reminder two days before the last day, as the wizard's "single".
            'reminderDays' => $template->reminder_days === null ? 'single' : (string) $template->reminder_days,
            'confidential' => $mayBeConfidential && $template->confidential,
            'internal' => $internal,
        ];
    }

    /** @return array<string, mixed> */
    private static function field(SigningField $field, int $signer): array
    {
        $options = (array) ($field->options ?? []);

        return [
            'signer' => $signer,
            'type' => $field->type->value,
            'page' => $field->page,
            'x' => $field->x,
            'y' => $field->y,
            'width' => $field->width,
            'height' => $field->height,
            'label' => $field->label,
            'required' => $field->required,
            'options_text' => match ($field->type) {
                FieldType::Dropdown => implode("\n", $options),
                FieldType::Radio => (string) ($options[0] ?? ''),
                default => '',
            },
            'group_key' => $field->group_key,
            'rules' => [
                'max_length' => (string) ($field->rules['max_length'] ?? ''),
                'min' => (string) ($field->rules['min'] ?? ''),
                'max' => (string) ($field->rules['max'] ?? ''),
                'decimals' => (string) ($field->rules['decimals'] ?? '0'),
            ],
        ];
    }
}
