<?php

declare(strict_types=1);

namespace App\Actions\Hq;

use App\Models\Company;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use App\Support\Instance\InstanceSettings;
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
     * @param  array<string, mixed>  $input  operator_company_id, customers_company_id, waitlist_company_id, waitlist_open, registration_open
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
            'customers_company_id' => ['nullable', 'integer', $company],
            'waitlist_company_id' => ['nullable', 'integer', $company],
            'waitlist_open' => ['required', 'boolean'],
            'registration_open' => ['required', 'boolean'],
        ], [], [
            'operator_company_id' => __('driftbolag'),
            'customers_company_id' => __('företag för kunderna'),
            'waitlist_company_id' => __('företag för kön'),
        ])->validate();

        $id = fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;
        $operator = $id($validated['operator_company_id'] ?? null);

        $new = [
            InstanceSettings::OPERATOR => $operator,
            // Without an operator company there is nothing to send anywhere.
            InstanceSettings::CUSTOMERS_TARGET => $operator === null ? null : $id($validated['customers_company_id'] ?? null),
            InstanceSettings::WAITLIST_TARGET => $operator === null ? null : $id($validated['waitlist_company_id'] ?? null),
            InstanceSettings::WAITLIST_OPEN => (bool) $validated['waitlist_open'],
            InstanceSettings::REGISTRATION_OPEN => (bool) $validated['registration_open'],
        ];

        $settings = InstanceSettings::current();
        $before = $settings->all();
        $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return [];
        }

        DB::transaction(function () use ($admin, $new, $before, $changed): void {
            foreach ($changed as $key) {
                InstanceSetting::query()->updateOrCreate(['key' => $key], ['value' => $new[$key], 'updated_by' => $admin->id]);
            }

            // Ids and booleans only.
            Audit::instance('hq.settings_updated', null, [
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($new, array_flip($changed)),
            ], $admin);
        });

        $settings->forget();

        return $changed;
    }
}
