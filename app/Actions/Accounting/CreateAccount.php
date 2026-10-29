<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateAccount
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input): Account
    {
        Gate::forUser($user)->authorize('create', [Account::class, $company]);

        $validated = Validator::make($input, [
            'number' => [
                'required', 'integer', 'between:1000,9999',
                Rule::unique('accounts', 'number')->where('company_id', $company->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(AccountType::class)],
        ], [
            'number.unique' => __('Konto :number finns redan i kontoplanen.', ['number' => $input['number'] ?? '']),
            'number.between' => __('Kontonumret ska ha fyra siffror, mellan 1000 och 9999.'),
        ], [
            'number' => __('kontonummer'),
            'name' => __('kontonamn'),
        ])->validate();

        return DB::transaction(fn (): Account => CurrentCompany::run($company, function () use ($validated, $company, $user): Account {
            $number = (int) $validated['number'];

            $account = Account::create([
                'number' => $number,
                'name' => trim((string) $validated['name']),
                'type' => $validated['type'] ?? AccountType::forNumber($number),
            ]);

            Audit::record('account.created', $account, [
                'number' => $account->number,
                'name' => $account->name,
                'type' => $account->type->value,
            ], $company, $user);

            return $account;
        }));
    }
}
