<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Invoicing\DefaultVat;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Ny offert" on an open deal: a quote for the deal's customer, linked to
 * the deal (quote_id) so that winning it needs no manual pick. With a
 * template the quote is made by CreateQuoteFromTemplate; without one it
 * gets a single row from the deal (its title, its value, the VAT rate invoicing would give (DefaultVat) and the
 * invoicing core's default revenue account), editable in the quote editor.
 * A prospect is made a customer first through ConvertToCustomer, as
 * WinDeal does, when the user may add customers; otherwise the quote is
 * refused with a message saying so. Needs update on the deal and
 * invoices:create.
 */
final class CreateQuoteForDeal
{
    /** Do not compare with the quote the caller saw. */
    public const string ANY = '*';

    public function __construct(
        private readonly ConvertToCustomer $convert,
        private readonly CreateQuoteFromTemplate $fromTemplate,
        private readonly SaveSalesDocument $save,
    ) {}

    public function __invoke(User $user, Company $company, Deal $deal, ?QuoteTemplate $template = null, ?string $replacing = self::ANY): SalesDocument
    {
        Gate::forUser($user)->authorize('update', $deal);
        Gate::forUser($user)->authorize('create', [SalesDocument::class, $company]);

        return DB::transaction(fn (): SalesDocument => CurrentCompany::run($company, function () use ($user, $company, $deal, $template, $replacing): SalesDocument {
            $deal = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();

            if (! $deal->isOpen()) {
                throw ValidationException::withMessages(['deal' => __('Affären är redan avslutad.')]);
            }

            // A second click, or another tab, must not leave a second quote behind.
            if ($replacing !== self::ANY && $deal->quote_id !== $replacing) {
                throw ValidationException::withMessages(['deal' => __('Affären har redan fått en offert. Ladda om sidan och försök igen.')]);
            }

            $replacedQuote = $deal->quote_id;
            $customerId = $this->customerId($user, $company, $deal);

            $quote = $template instanceof QuoteTemplate
                ? ($this->fromTemplate)($user, $company, $template, $customerId)
                : $this->plain($user, $company, $deal, $customerId);

            // A new quote replaces the old link, and the order and invoice that belonged to the old quote go with it.
            $deal->forceFill(['quote_id' => $quote->id, 'order_id' => null, 'invoice_id' => null])->save();
            Account::query()->whereKey($deal->account_id)->update(['last_activity_at' => now()]);

            Audit::record('crm.deal_quote_created', $deal, array_filter(['deal_id' => $deal->id, 'quote_id' => $quote->id, 'template_id' => $template?->id, 'replaced_quote_id' => $replacedQuote]), $company, $user);

            return $quote;
        }));
    }

    private function customerId(User $user, Company $company, Deal $deal): int
    {
        $account = Account::query()->whereKey($deal->account_id)->lockForUpdate()->firstOrFail();

        if ($account->customer_id === null) {
            if (! $user->can('create', [Customer::class, $company])) {
                throw ValidationException::withMessages(['deal' => __('Prospekten är inte kund än, och du får inte lägga till kunder. Be någon med behörighet göra den till kund först.')]);
            }

            ($this->convert)($user, $company, $account);
            $account->refresh();
        }

        return (int) $account->customer_id;
    }

    private function plain(User $user, Company $company, Deal $deal, int $customerId): SalesDocument
    {
        $today = CarbonImmutable::now(LogActivity::ZONE);
        $customer = Customer::query()->findOrFail($customerId);

        return ($this->save)($user, $company, SalesDocument::QUOTE, [
            'customer_id' => $customerId,
            'document_date' => $today->toDateString(),
            'until_date' => $today->addDays(30)->toDateString(),
            'rows' => [[
                'description' => $deal->title,
                'quantity' => '1',
                'unit' => 'st',
                'price' => Money::ofOre($deal->value_ore)->format(withCurrency: false),
                'vat_rate' => DefaultVat::rateFor($company, $customer),
            ]],
        ]);
    }
}
