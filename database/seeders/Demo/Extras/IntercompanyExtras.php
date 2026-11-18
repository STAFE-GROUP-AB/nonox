<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\CreateAccount;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Group\MarkIntercompanyCounterparty;
use App\Actions\Group\RefreshIntercompanyTags;
use App\Actions\Group\TagIntercompanyRows;
use App\Actions\Payables\SaveSupplier;
use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanySide;
use App\Models\Customer;
use App\Models\Supplier;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\Demo\DemoIndustries;

/**
 * Koncernmarkeringar (ADR-060 addendum 2.3, ADR-081 E23) for the manual, in
 * the consultancy: it belongs to a made-up group with a holding company
 * that lent it money, and one of its customers is a sister company. The
 * loan and the quarter's interest are posted by hand; afterwards the
 * customer and the holding company are marked, the invoice rows are tagged
 * from the marking and the loan and interest rows in bulk, as a person
 * would. Every name and number is made up.
 */
final class IntercompanyExtras extends DemoExtra
{
    public const string HOLDING = 'Konsultgruppen Holding Exempel AB';

    public const string SISTER = 'Mjukvaruhuset Exempel AB';

    public const int LOAN_ACCOUNT = 2360;

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function schedule(DemoContext $c): void
    {
        app(SaveSupplier::class)($c->user, $c->company, [
            'name' => self::HOLDING, 'org_number' => self::holdingOrgNumber(), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 6550,
        ]);
        app(CreateAccount::class)($c->user, $c->company, ['number' => self::LOAN_ACCOUNT, 'name' => 'Långfristiga skulder till koncernföretag']);

        $loanDay = $c->start->addMonth()->setDay(16);
        $c->on($loanDay, 20, fn () => $this->post($c, $loanDay, 'Lån från moderbolaget', [
            ['account' => 1930, 'debit_ore' => 200_000_00],
            ['account' => self::LOAN_ACCOUNT, 'credit_ore' => 200_000_00],
        ]));

        foreach ([3, 6, 9] as $month) {
            $day = $c->start->addMonths($month - 1)->endOfMonth()->startOfDay();

            if ($day->greaterThan($c->today)) {
                continue;
            }

            $c->on($day, 20, fn () => $this->post($c, $day, 'Ränta på lånet från moderbolaget', [
                ['account' => 8410, 'debit_ore' => 2_500_00],
                ['account' => 1930, 'credit_ore' => 2_500_00],
            ]));
        }
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Koncernmarkeringar', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $sister = Customer::query()->where('name', self::SISTER)->firstOrFail();
            $holding = Supplier::query()->where('name', self::HOLDING)->firstOrFail();

            app(MarkIntercompanyCounterparty::class)($c->user, $c->company, IntercompanySide::Customer, $sister->id);
            app(MarkIntercompanyCounterparty::class)($c->user, $c->company, IntercompanySide::Supplier, $holding->id);
            app(RefreshIntercompanyTags::class)($c->user, $c->company);

            foreach ([self::LOAN_ACCOUNT => IntercompanyKind::LoanPayable, 8410 => IntercompanyKind::InterestExpense] as $account => $kind) {
                app(TagIntercompanyRows::class)($c->user, $c->company, [
                    'account' => (string) $account,
                    'from' => $c->start->toDateString(),
                    'to' => $c->end->toDateString(),
                    'counterparty_org_number' => self::holdingOrgNumber(),
                    'kind' => $kind->value,
                ]);
            }
        }));
    }

    public static function holdingOrgNumber(): string
    {
        return DemoIndustries::orgNumber('88');
    }

    /** @param  list<array<string, int|string>>  $rows */
    private function post(DemoContext $c, CarbonImmutable $date, string $description, array $rows): void
    {
        app(PostVoucher::class)($c->user, app(SaveDraftVoucher::class)($c->user, $c->company, [
            'voucher_date' => $date->toDateString(),
            'description' => $description,
            'rows' => $rows,
        ]));
    }
}
