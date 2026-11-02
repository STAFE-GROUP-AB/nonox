<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\AccountType;
use App\Domain\Vat\VatBoxes;
use App\Models\Account;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Renames, retypes, activates or deactivates an account, ties it to a box of
 * the VAT return (vat_box, #64) or sets its SRU code (e.g. 7512 for purchases
 * of goods for resale, #89). The account number never changes. Accounts are
 * deactivated, never deleted (AGENTS.md §6.1).
 */
class UpdateAccount
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Account $account, array $input): void
    {
        Gate::forUser($user)->authorize('update', $account);

        $validated = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::enum(AccountType::class)],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'vat_box' => ['sometimes', 'nullable', 'integer', Rule::in(VatBoxes::ACCOUNT_BOXES)],
            'sru_code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{3,4}$/'],
        ], [
            'vat_box.in' => __('Välj en ruta för försäljning eller inköp.'),
        ], [
            'name' => __('kontonamn'),
        ])->validate();

        if (($validated['vat_box'] ?? null) !== null && ! VatBoxes::canHaveBox($account->number)) {
            throw ValidationException::withMessages(['vat_box' => __('Bara intäkts- och kostnadskonton (3000–8999) kan kopplas till en momsruta. Momskontona följer BAS.')]);
        }

        $before = $this->snapshot($account);

        $account->fill([
            ...$validated,
            ...(isset($validated['name']) ? ['name' => trim((string) $validated['name'])] : []),
        ])->save();

        $changes = Audit::changes($before, $this->snapshot($account));

        if ($changes['after'] !== []) {
            Audit::record('account.updated', $account, [
                'number' => $account->number,
                ...$changes,
            ], $account->company_id, $user);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Account $account): array
    {
        return [
            'name' => $account->name,
            'type' => $account->type->value,
            'is_active' => $account->is_active,
            'vat_box' => $account->vat_box,
            'sru_code' => $account->sru_code,
        ];
    }
}
