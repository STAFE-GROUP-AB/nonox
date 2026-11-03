<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Marks an open deal as won (ADR-043 stage 2). The value can be adjusted to
 * what was agreed (`value_ore`). Optionally the quote, order or invoice the
 * deal led to is linked (`quote_id`, `order_id`, `invoice_id`): it must be
 * the account's customer's, and a quote is a quote and an order an order.
 * A prospect becomes a customer through the customer register's own action
 * (ConvertToCustomer) when `make_customer` is set and the user may add
 * customers; its status stays Prospekt until the first invoice, as in
 * stage 1. Nothing is written to the ledger.
 */
final class WinDeal
{
    public function __construct(private readonly ConvertToCustomer $convert) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, Deal $deal, array $input = []): Deal
    {
        Gate::forUser($user)->authorize('update', $deal);

        $validated = Validator::make($input, [
            'value_ore' => ['sometimes', 'integer', 'min:0', 'max:'.(PHP_INT_MAX / 100)],
            'quote_id' => ['nullable', 'uuid'],
            'order_id' => ['nullable', 'uuid'],
            'invoice_id' => ['nullable', 'uuid'],
            'make_customer' => ['sometimes', 'boolean'],
        ], [], ['value_ore' => __('värde')])->validate();

        return DB::transaction(fn (): Deal => CurrentCompany::run($company, function () use ($user, $company, $deal, $validated): Deal {
            $deal = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();

            if (! $deal->isOpen()) {
                throw ValidationException::withMessages(['deal' => __('Affären är redan avslutad.')]);
            }

            $account = Account::query()->findOrFail($deal->account_id);

            if ($account->customer_id === null && (bool) ($validated['make_customer'] ?? false) && $user->can('create', [Customer::class, $company])) {
                ($this->convert)($user, $company, $account);
                $account->refresh();
            }

            $links = $this->links($validated, $account);

            $deal->forceFill([
                ...$links,
                'value_ore' => (int) ($validated['value_ore'] ?? $deal->value_ore),
                'status' => DealStatus::Won,
                'won_at' => now(),
                'lost_at' => null,
                'loss_reason_id' => null,
                'loss_note' => null,
                'win_back_at' => null,
            ])->save();

            Account::query()->whereKey($deal->account_id)->update(['last_activity_at' => now()]);
            Audit::record('crm.deal_won', $deal, ['deal_id' => $deal->id, 'account_id' => $deal->account_id, 'stage_id' => $deal->stage_id, ...array_filter($links)], $company, $user);

            return $deal;
        }));
    }

    /**
     * The ledger documents to link, each checked to be the customer's.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, ?string>
     */
    private function links(array $validated, Account $account): array
    {
        $links = [];

        foreach (['quote_id' => SalesDocument::QUOTE, 'order_id' => SalesDocument::ORDER] as $field => $kind) {
            if (blank($validated[$field] ?? null)) {
                continue;
            }

            $exists = $account->customer_id !== null && SalesDocument::query()
                ->whereKey((string) $validated[$field])
                ->where('kind', $kind)
                ->where('customer_id', $account->customer_id)
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([$field => $kind === SalesDocument::QUOTE ? __('Offerten finns inte hos kunden.') : __('Ordern finns inte hos kunden.')]);
            }

            $links[$field] = (string) $validated[$field];
        }

        if (! blank($validated['invoice_id'] ?? null)) {
            $exists = $account->customer_id !== null && Invoice::query()
                ->whereKey((string) $validated['invoice_id'])
                ->where('customer_id', $account->customer_id)
                ->where('kind', Invoice::INVOICE)
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages(['invoice_id' => __('Fakturan finns inte hos kunden.')]);
            }

            $links['invoice_id'] = (string) $validated['invoice_id'];
        }

        return $links;
    }
}
