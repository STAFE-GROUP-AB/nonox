<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Models\RegistryChange;
use App\Models\RegistryCompany;
use App\Support\CompanyRegistry\RegistryCodes;
use App\Support\CompanyRegistry\Sni;
use Carbon\CarbonImmutable;

/**
 * A change in the company register in plain Swedish (ADR-047), for the
 * monitoring task: "Ny adress: Exempelgatan 1 → Nya gatan 10".
 */
final class RegistryChangeText
{
    /** @return array<string, string> field => what it is called in a task */
    public static function labels(): array
    {
        return [
            'name' => __('Nytt namn'),
            'address_line1' => __('Ny adress'),
            'postal_code' => __('Nytt postnummer'),
            'city' => __('Ny ort'),
            'municipality_code' => __('Ny kommun'),
            'legal_form' => __('Ny bolagsform'),
            'sni_code' => __('Ny bransch'),
            'scb_status' => __('Ändrad verksamhetsstatus'),
            'bolagsverket_status' => __('Ändrad status hos Bolagsverket'),
            'deregistered_on' => __('Avregistrerat'),
            'proceedings' => __('Konkurs, likvidation eller rekonstruktion'),
            'employee_class' => __('Ändrat antal anställda'),
            'turnover_class' => __('Ändrad omsättning'),
        ];
    }

    public static function line(RegistryChange $change): string
    {
        $label = self::labels()[$change->field] ?? $change->field;
        $old = self::value($change->field, $change->old_value);
        $new = self::value($change->field, $change->new_value);

        return "{$label}: ".($old ?? '–').' → '.($new ?? __('inget'));
    }

    /**
     * A few words for the subject: "ny adress, konkurs".
     *
     * @param  list<string>  $fields
     */
    public static function summary(array $fields): string
    {
        $words = array_map(fn (string $field): string => mb_strtolower(self::labels()[$field] ?? $field), $fields);

        return implode(', ', array_slice(array_unique($words), 0, 3)).(count($words) > 3 ? ' …' : '');
    }

    private static function value(string $field, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'city' => RegistryCompany::titleCase($value),
            'legal_form' => RegistryCodes::legalForm($value),
            'sni_code' => Sni::label($value),
            'scb_status' => match ($value) {
                '1' => (string) __('verksamt'),
                '9' => (string) __('inte längre verksamt'),
                default => (string) __('aldrig verksamt'),
            },
            'bolagsverket_status' => RegistryCodes::bolagsverketStatus((int) $value),
            'deregistered_on' => CarbonImmutable::parse($value)->translatedFormat('j M Y'),
            'proceedings' => RegistryCodes::proceedings($value),
            'employee_class' => RegistryCodes::employees((int) $value),
            'turnover_class' => RegistryCodes::turnover((int) $value),
            'municipality_code' => RegistryCodes::table('municipality')[$value] ?? $value,
            default => $value,
        };
    }
}
