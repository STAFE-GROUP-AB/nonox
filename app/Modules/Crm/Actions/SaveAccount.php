<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates an account (a prospect) or changes one (ADR-043). On an account
 * that is a customer, the fields it shares with the customer register are
 * changed through SaveCustomer, so the register stays the source and the
 * same permission applies: a seller without invoices:create changes only
 * the CRM fields.
 */
final class SaveAccount
{
    /** @var list<string> fields only the CRM has */
    public const array CRM_FIELDS = ['kind', 'owner_id', 'parent_id', 'sni_code', 'website', 'source', 'tags'];

    public function __construct(private readonly SaveCustomer $saveCustomer) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Account $account = null): Account
    {
        $account === null
            ? Gate::forUser($user)->authorize('manage', [Account::class, $company])
            : Gate::forUser($user)->authorize('update', $account);

        if (is_string($input['tags'] ?? null)) {
            $input['tags'] = explode(',', $input['tags']);
        }

        $memberIds = CompanyMembers::ids($company);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'org_number' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (OrganisationNumber::tryFrom((string) $value) === null) {
                    $fail(__('Organisationsnumret stämmer inte. Kontrollera siffrorna.'));
                }
            }],
            'kind' => ['sometimes', Rule::enum(AccountKind::class)],
            'owner_id' => ['nullable', 'integer', Rule::in($memberIds)],
            'parent_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($account): void {
                if ($account !== null && (int) $value === $account->id) {
                    $fail(__('Ett företag kan inte vara sitt eget moderbolag.'));

                    return;
                }

                if (! Account::query()->whereKey((int) $value)->exists()) {
                    $fail(__('Moderbolaget finns inte bland kunderna.'));
                }
            }],
            'sni_code' => ['nullable', 'string', 'regex:/^\d{2}(\.?\d{1,3})?$/'],
            'website' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'source' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['nullable', 'string', 'max:40'],
        ], [
            'owner_id.in' => __('Ansvarig säljare måste vara med i företaget.'),
            'sni_code.regex' => __('SNI-koden skrivs med siffror, till exempel 62010 eller 62.010.'),
            'country.regex' => __('Ange landet som en landskod med två bokstäver, till exempel SE.'),
        ], [
            'name' => __('namn'),
            'email' => __('e-post'),
        ])->validate();

        $attributes = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $validated);

        if (array_key_exists('tags', $attributes)) {
            $attributes['tags'] = array_values(array_unique(array_filter(array_map(fn (mixed $tag): string => mb_strtolower(trim((string) $tag)), (array) $attributes['tags']), fn (string $tag): bool => $tag !== '')));
        }

        if (isset($attributes['org_number'])) {
            $attributes['org_number'] = OrganisationNumber::tryFrom((string) $attributes['org_number'])?->formatted();
        }

        if (array_key_exists('country', $attributes)) {
            $attributes['country'] = strtoupper((string) ($attributes['country'] ?? 'SE'));
        }

        return DB::transaction(fn (): Account => CurrentCompany::run($company, function () use ($user, $company, $attributes, $account): Account {
            if ($account === null) {
                $account = Account::create([...$attributes, 'owner_id' => $attributes['owner_id'] ?? $user->id, 'last_activity_at' => now()]);

                Audit::record('crm.account_created', $account, ['name' => $account->name], $company, $user);

                return $account;
            }

            $customer = $account->customer_id === null ? null : Customer::query()->find($account->customer_id);
            $own = $customer === null ? $attributes : array_intersect_key($attributes, array_flip(self::CRM_FIELDS));
            $before = $account->only(array_keys($own));

            if ($customer !== null) {
                $this->saveSharedFields($user, $company, $customer, $attributes);
            }

            $account->fill($own)->forceFill(['last_activity_at' => now()])->save();

            Audit::record('crm.account_updated', $account, Audit::changes($before, $account->only(array_keys($own))), $company, $user);

            return $account->refresh();
        }));
    }

    /**
     * The shared fields of a customer's account go to the customer register,
     * only when one of them changes, and with the register's own rules.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function saveSharedFields(User $user, Company $company, Customer $customer, array $attributes): void
    {
        $shared = array_intersect_key($attributes, array_flip(Account::CUSTOMER_FIELDS));
        $current = $customer->only(array_keys($shared));

        if ($shared === [] || $shared == $current) {
            return;
        }

        $fields = ['name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'payment_terms_days', 'is_active', 'reverse_charge_construction', 'foreign_sale', 'currency'];
        $input = [];

        foreach ($fields as $field) {
            $value = $customer->getAttribute($field);
            $input[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        ($this->saveCustomer)($user, $company, [...$input, ...$shared], $customer);
    }
}
