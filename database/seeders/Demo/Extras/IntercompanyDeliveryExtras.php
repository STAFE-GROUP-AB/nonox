<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\CreateAccount;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Group\CreatePairedDraft;
use App\Actions\Group\DeliverIntercompanyInvoice;
use App\Actions\Group\MarkIntercompanyCounterparty;
use App\Actions\Group\SetIntercompanyReceipt;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Domain\Group\IntercompanyDeliveryStatus;
use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanySide;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\IntercompanyDelivery;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Intra-group invoices and paired drafts (ADR-081 E27) for the manual: the
 * consulting company is the parent of the café (GroupExtras, which runs
 * first). The café accepts group invoices from the parent; the parent
 * invoices the café for administrative services and delivers the invoice
 * into the café's inbox; and the parent lends the café 100 000 kr and makes
 * the café's matching draft. Everything goes through the app's own actions
 * as the demo owner, who administers every demo company. Every name and
 * number is made up.
 */
final class IntercompanyDeliveryExtras extends DemoExtra
{
    private const string PARENT = 'Konsultbolaget Exempel Demo AB';

    private const string SUBSIDIARY = 'Café Hörnet Demo AB';

    public const string INVOICE_REFERENCE = 'Administration inom koncernen';

    public const string LOAN = 'Lån till Café Hörnet Demo AB';

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Koncernleveranser', function () use ($c): void {
            $parent = Company::query()->where('name', self::PARENT)->first();
            $cafe = Company::query()->where('name', self::SUBSIDIARY)->first();

            if (! $parent instanceof Company || ! $cafe instanceof Company) {
                $c->warn('Koncernleveranserna hoppas över: demoföretagen finns inte.');

                return;
            }

            // Idempotent: a second run (or a reseed on top) adds nothing.
            $done = CurrentCompany::run($parent, fn (): bool => IntercompanyDelivery::query()
                ->where('recipient_org_number', $cafe->org_number)
                ->where('status', IntercompanyDeliveryStatus::Delivered->value)
                ->exists() || Voucher::query()->where('description', self::LOAN)->exists());

            if ($done) {
                return;
            }

            $user = $c->user;
            $modules = app(ModuleManager::class);

            if (! $modules->isEnabled(InboxModule::KEY, $cafe)) {
                $modules->enable(InboxModule::KEY, $cafe, $user);
            }

            // The café: accepts group invoices from the parent, which is among its suppliers.
            app(SetIntercompanyReceipt::class)->enable($user, $cafe, (string) $parent->org_number);
            CurrentCompany::run($cafe, function () use ($user, $cafe, $parent): void {
                Supplier::query()->where('org_number', $parent->org_number)->exists() || app(SaveSupplier::class)($user, $cafe, [
                    'name' => self::PARENT, 'org_number' => $parent->org_number, 'vat_number' => $parent->vat_number,
                    'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 6550,
                ]);
                Account::query()->where('number', 2360)->exists() || app(CreateAccount::class)($user, $cafe, ['number' => 2360, 'name' => 'Långfristiga skulder till koncernföretag']);
            });

            CurrentCompany::run($parent, function () use ($c, $user, $parent, $cafe): void {
                $customer = Customer::query()->where('org_number', $cafe->org_number)->first()
                    ?? app(SaveCustomer::class)($user, $parent, [
                        'name' => self::SUBSIDIARY, 'org_number' => $cafe->org_number, 'vat_number' => $cafe->vat_number, 'payment_terms_days' => 30,
                        // ML 17 kap. 24 §: the buyer's address on an invoice to a company.
                        'address_line1' => $cafe->address_line1 ?: 'Storgatan 1', 'postal_code' => $cafe->postal_code ?: '111 22', 'city' => $cafe->city ?: 'Stockholm',
                    ]);
                app(MarkIntercompanyCounterparty::class)($user, $parent, IntercompanySide::Customer, $customer->id);

                $invoice = app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $parent, [
                    'customer_id' => $customer->id,
                    'invoice_date' => $c->today->subDays(3)->toDateString(),
                    'your_reference' => self::INVOICE_REFERENCE,
                    'rows' => [['description' => 'Ekonomi och administration, månad', 'quantity' => '1', 'price' => '12000', 'vat_rate' => 2500]],
                ]));
                app(DeliverIntercompanyInvoice::class)($user, $invoice);

                Account::query()->where('number', 1320)->exists() || app(CreateAccount::class)($user, $parent, ['number' => 1320, 'name' => 'Långfristiga fordringar hos koncernföretag']);
                $loan = app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $parent, [
                    'voucher_date' => $c->today->subDays(2)->toDateString(),
                    'description' => self::LOAN,
                    'rows' => [
                        ['account' => 1320, 'debit_ore' => 100_000_00, 'description' => 'Lån enligt avtal'],
                        ['account' => 1930, 'credit_ore' => 100_000_00],
                    ],
                ]));
                $proposal = app(CreatePairedDraft::class)->propose($user, $loan);
                app(CreatePairedDraft::class)($user, $loan, $cafe->id, [...$proposal, 'kind' => IntercompanyKind::LoanReceivable->value, 'description' => 'Lån från Konsultbolaget Exempel Demo AB']);
            });
        });
    }
}
