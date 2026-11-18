<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Accounting\CreateAccount;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\MarkIntercompanyCounterparty;
use App\Actions\Group\RecordHolding;
use App\Actions\Group\SetIntercompanyReceipt;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\IntercompanySide;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;

/**
 * Two NONOX companies in one corporate group for the intra-group tests
 * (ADR-081 E27): parent A ("Moder Exempel AB") owns 100 % of B ("Dotter
 * Exempel AB"). Olle owns A and is administrator in B (he set the group up);
 * Sara is a bookkeeper in A only and sends A's invoices; Bea owns B. B has
 * the inbox on and accepts invoices from A. A has B as a customer marked as
 * a group company, and an issued invoice to it.
 */
final class E27Group
{
    public User $olle;

    public User $sara;

    public User $bea;

    public Company $a;

    public Company $b;

    public CorporateGroup $group;

    public CompanyHolding $holding;

    public Customer $customer;

    public static function orgNumber(string $serial): string
    {
        $nine = '559871'.str_pad($serial, 3, '0', STR_PAD_LEFT);

        return substr($nine, 0, 6).'-'.substr($nine, 6).Luhn::checkDigit($nine);
    }

    public static function make(bool $receipt = true): self
    {
        $set = new self;
        [$set->olle, $set->a] = Bookkeeping::company();
        [$set->bea, $set->b] = Bookkeeping::company();

        $set->a->forceFill(['name' => 'Moder Exempel AB', 'org_number' => self::orgNumber('101'), 'vat_number' => 'SE'.str_replace('-', '', self::orgNumber('101')).'01'])->save();
        $set->b->forceFill(['name' => 'Dotter Exempel AB', 'org_number' => self::orgNumber('102'), 'vat_number' => 'SE'.str_replace('-', '', self::orgNumber('102')).'01'])->save();
        $set->a = $set->a->fresh();
        $set->b = $set->b->fresh();

        $set->b->users()->attach($set->olle, ['role' => 'admin']);
        $set->olle = $set->olle->fresh();
        $set->sara = Bookkeeping::member($set->a, 'bookkeeper');

        $set->holding = app(RecordHolding::class)($set->olle, $set->a, [
            'held_org_number' => $set->b->org_number,
            'held_name' => $set->b->name,
            'capital' => '100',
            'voting' => '100',
            'control_basis' => ControlBasis::Votes->value,
            'acquired_on' => '2024-01-01',
        ]);
        $set->group = app(CreateCorporateGroup::class)($set->olle->fresh(), $set->a, 'Exempelkoncernen');
        app(ConfirmGroupMember::class)($set->olle->fresh(), $set->group->id, $set->b);

        app(ModuleManager::class)->enable(InboxModule::KEY, $set->b, $set->bea);

        if ($receipt) {
            app(SetIntercompanyReceipt::class)->enable($set->bea, $set->b, (string) $set->a->org_number);
        }

        $set->customer = CurrentCompany::run($set->a, function () use ($set): Customer {
            $customer = app(SaveCustomer::class)($set->olle, $set->a, [
                'name' => 'Dotter Exempel AB', 'org_number' => $set->b->org_number, 'vat_number' => $set->b->vat_number, 'payment_terms_days' => 30,
            ]);
            app(MarkIntercompanyCounterparty::class)($set->olle, $set->a, IntercompanySide::Customer, $customer->id);

            return $customer;
        });

        // B has A among its suppliers, so the received invoice is matched to it.
        CurrentCompany::run($set->b, fn () => app(SaveSupplier::class)($set->bea, $set->b, [
            'name' => 'Moder Exempel AB', 'org_number' => $set->a->org_number, 'vat_number' => $set->a->vat_number,
            'iban' => 'SE4550000000058398257466', 'bic' => 'ESSESESS', 'payment_terms_days' => 30, 'default_account' => 4010,
        ]));

        return $set;
    }

    /** An issued invoice from A to B for 1 000 kr plus VAT. */
    public function invoice(string $price = '1000', ?Customer $customer = null): Invoice
    {
        return CurrentCompany::run($this->a, fn (): Invoice => app(IssueInvoice::class)($this->olle, app(SaveDraftInvoice::class)($this->olle, $this->a, [
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_date' => '2026-03-02',
            'rows' => [['description' => 'Administrativa tjänster inom koncernen', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500]],
        ])));
    }

    /** Adds an account to a company's chart. */
    public function account(Company $company, int $number, string $name): void
    {
        $owner = $company->id === $this->a->id ? $this->olle : $this->bea;
        CurrentCompany::run($company, fn () => app(CreateAccount::class)($owner, $company, ['number' => $number, 'name' => $name]));
    }
}
