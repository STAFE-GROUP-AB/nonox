<?php

declare(strict_types=1);

namespace App\Actions\Hq;

use App\Models\Company;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use App\Support\Instance\InstanceSettings;
use App\Support\Status\SecurityTxt;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Changes the installation's settings in /hq (ADR-044). Only an HQ admin;
 * the companies must exist, not be archived and not be test companies.
 * Every change is written to the instance audit log with the keys and the
 * values before and after: company ids and booleans, never a name.
 *
 * Returns the keys that changed.
 */
final class UpdateInstanceSettings
{
    /**
     * @param  array<string, mixed>  $input  operator_company_id, waitlist_open, registration_open, security_contact (optional: left as it is when absent)
     * @return list<string>
     */
    public function __invoke(User $admin, array $input): array
    {
        HqAccess::ensure($admin);

        $company = function (string $attribute, mixed $value, Closure $fail): void {
            $exists = Company::query()->whereKey((int) $value)->whereNull('archived_at')->where('is_test_company', false)->exists();

            if (! $exists) {
                $fail(__('Välj ett företag i installationen som inte är arkiverat eller ett testföretag.'));
            }
        };

        $validated = Validator::make($input, [
            'operator_company_id' => ['nullable', 'integer', $company],
            'waitlist_open' => ['required', 'boolean'],
            'registration_open' => ['required', 'boolean'],
            'security_contact' => ['sometimes', 'nullable', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && SecurityTxt::contactUri($value) === null) {
                    $fail(__('Ange en e-postadress eller en webbadress som börjar med https://.'));
                }
            }],
        ], [], [
            'operator_company_id' => __('driftbolag'),
            'security_contact' => __('säkerhetskontakt'),
        ])->validate();

        $id = fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;
        $operator = $id($validated['operator_company_id'] ?? null);

        $settings = InstanceSettings::current();
        $before = $settings->all();
        $contact = array_key_exists('security_contact', $validated)
            ? trim((string) $validated['security_contact'])
            : $before[InstanceSettings::SECURITY_CONTACT];

        $new = [
            InstanceSettings::OPERATOR => $operator,
            InstanceSettings::WAITLIST_OPEN => (bool) $validated['waitlist_open'],
            InstanceSettings::REGISTRATION_OPEN => (bool) $validated['registration_open'],
            InstanceSettings::SECURITY_CONTACT => $contact === '' ? null : $contact,
        ];

        $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        // The contact may be a person's address: the log says that it was set or cleared, not what it is.
        $logged = function (array $values): array {
            if (($values[InstanceSettings::SECURITY_CONTACT] ?? null) !== null) {
                $values[InstanceSettings::SECURITY_CONTACT] = true;
            }

            return $values;
        };

        if ($changed === []) {
            return [];
        }

        DB::transaction(function () use ($admin, $new, $before, $changed, $logged): void {
            foreach ($changed as $key) {
                InstanceSetting::query()->updateOrCreate(['key' => $key], ['value' => $new[$key], 'updated_by' => $admin->id]);
            }

            // Ids and booleans only.
            Audit::instance('hq.settings_updated', null, [
                'before' => $logged(array_intersect_key($before, array_flip($changed))),
                'after' => $logged(array_intersect_key($new, array_flip($changed))),
            ], $admin);
        });

        $settings->forget();

        return $changed;
    }
}
