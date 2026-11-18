<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\CreateAccount;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\MapGroupAccount;
use App\Actions\Group\RecordGroupEntry;
use App\Actions\Group\RecordHolding;
use App\Actions\Group\TagIntercompanyRows;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\EliminationSuggestions;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\IntercompanyKind;
use App\Models\Account;
use App\Models\Company;
use App\Models\CorporateGroup;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Koncern → Konsolidering (ADR-081 E24) for the manual: the consulting
 * company's group (the same structure as GroupExtras, which runs first and
 * is reused when it exists), one account map row for the café, intra-group
 * fees with one accepted elimination and one difference (ADR-081 E25), and
 * two runs in the run log: the first half-year and the whole year. Everything goes
 * through the app's own actions as the demo owner, who administers every
 * demo company.
 */
final class GroupRunExtras extends DemoExtra
{
    private const string PARENT = 'Konsultbolaget Exempel Demo AB';

    /** Holder, held, share of capital and votes (percent), acquisition date: as GroupExtras. */
    private const array HOLDINGS = [
        [self::PARENT, 'Café Hörnet Demo AB', '100', '2023-01-02'],
        [self::PARENT, 'Butik Lindgården Demo AB', '80', '2024-04-01'],
        ['Butik Lindgården Demo AB', 'Salong Saxen Demo AB', '60', '2025-02-03'],
    ];

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Konsolidering', function () use ($c): void {
            $companies = Company::query()->whereIn('name', [self::PARENT, ...array_column(self::HOLDINGS, 1)])->get()->keyBy('name');

            if ($companies->count() < 4) {
                $c->warn('Konsolideringen hoppas över: demoföretagen finns inte alla.');

                return;
            }

            $company = fn (string $name): Company => $companies->get($name) ?? throw new \RuntimeException("Demoföretaget {$name} finns inte.");
            $group = CorporateGroup::query()->where('parent_company_id', $company(self::PARENT)->id)->first() ?? $this->createGroup($c, $company);
            $cafe = $company('Café Hörnet Demo AB');

            if (CurrentCompany::run($cafe, fn (): bool => Account::query()->where('number', 3001)->exists())) {
                app(MapGroupAccount::class)->save($c->user, $group->id, $cafe->id, ['account' => '3001', 'group_account' => '3000']);
            }

            $start = $c->fiscalYear->start_date;
            $this->eliminations($c, $group, $company);
            app(ConsolidateGroup::class)($c->user, $group->id, ['from' => $start->toDateString(), 'to' => $start->addMonths(6)->subDay()->toDateString()]);
            app(ConsolidateGroup::class)($c->user, $group->id, ['from' => $start->toDateString(), 'to' => $c->fiscalYear->end_date->toDateString()]);
        });
    }

    /**
     * Koncern → Elimineringar (ADR-081 E25): the parent invoices the café a
     * group service fee and the shop the same, on accounts kept for
     * intra-group items, tagged in each company through the bulk tagging a
     * person uses. The café tags its side too, so that pair matches and is
     * accepted into the group ledger; the shop has not tagged its side yet,
     * so its pair shows a difference.
     *
     * @param  callable(string): Company  $company
     */
    private function eliminations(DemoContext $c, CorporateGroup $group, callable $company): void
    {
        $parent = $company(self::PARENT);
        $day = $c->start->addMonths(2)->setDay(15);
        $from = $c->fiscalYear->start_date->toDateString();
        $to = $c->fiscalYear->end_date->toDateString();

        foreach (['Café Hörnet Demo AB' => true, 'Butik Lindgården Demo AB' => false] as $name => $tagged) {
            $held = $company($name);
            $this->intragroup($c, $parent, $day, [1660 => 1_500_000, 3021 => -1_500_000], [1660 => IntercompanyKind::Receivable, 3021 => IntercompanyKind::Sales], $held);
            $this->intragroup($c, $held, $day, [6591 => 1_500_000, 2860 => -1_500_000], $tagged ? [6591 => IntercompanyKind::Purchases, 2860 => IntercompanyKind::Payable] : [], $parent);
        }

        $access = GroupAccess::for($c->user);
        $suggestions = app(EliminationSuggestions::class)->compute($access, $group->fresh() ?? $group, CarbonImmutable::parse($from), CarbonImmutable::parse($to))['suggestions'];

        foreach ($suggestions as $suggestion) {
            if ($suggestion['status'] === EliminationSuggestions::MATCHED && $suggestion['accepted_entry'] === null) {
                app(RecordGroupEntry::class)->accept($c->user, $group->id, ['from' => $from, 'to' => $to, 'key' => $suggestion['key'], 'fingerprint' => $suggestion['fingerprint']]);
            }
        }
    }

    /**
     * One intra-group voucher in a company, its rows tagged with the counterparty.
     *
     * @param  array<int, int>  $rows  account => signed öre
     * @param  array<int, IntercompanyKind>  $kinds  account => kind of the rows to tag
     */
    private function intragroup(DemoContext $c, Company $company, CarbonImmutable $day, array $rows, array $kinds, Company $counterparty): void
    {
        CurrentCompany::run($company, function () use ($c, $company, $day, $rows): void {
            foreach (array_keys($rows) as $number) {
                if (! Account::query()->where('number', $number)->exists()) {
                    app(CreateAccount::class)($c->user, $company, ['number' => $number, 'name' => self::ACCOUNT_NAMES[$number]]);
                }
            }

            app(PostVoucher::class)($c->user, app(SaveDraftVoucher::class)($c->user, $company, [
                'voucher_date' => $day->toDateString(),
                'description' => 'Koncernarvode',
                'rows' => array_map(fn (int $account, int $amount): array => ['account' => $account, 'debit_ore' => max($amount, 0), 'credit_ore' => max(-$amount, 0)], array_keys($rows), $rows),
            ]));
        });

        foreach ($kinds as $account => $kind) {
            app(TagIntercompanyRows::class)($c->user, $company, [
                'account' => (string) $account,
                'from' => $day->toDateString(),
                'to' => $day->toDateString(),
                'counterparty_org_number' => $counterparty->org_number,
                'kind' => $kind->value,
            ]);
        }
    }

    /** BAS names of the intra-group accounts the demo creates when a company lacks them. */
    private const array ACCOUNT_NAMES = [
        1660 => 'Kortfristiga fordringar hos koncernföretag',
        2860 => 'Kortfristiga skulder till koncernföretag',
        3021 => 'Koncernintern försäljning av tjänster',
        6591 => 'Koncerninterna tjänster',
    ];

    /** @param  callable(string): Company  $company */
    private function createGroup(DemoContext $c, callable $company): CorporateGroup
    {
        foreach (self::HOLDINGS as [$holder, $held, $share, $acquired]) {
            app(RecordHolding::class)($c->user, $company($holder), [
                'held_org_number' => $company($held)->org_number,
                'held_name' => $held,
                'capital' => $share,
                'voting' => $share,
                'control_basis' => ControlBasis::Votes->value,
                'acquired_on' => $acquired,
            ]);
        }

        $group = app(CreateCorporateGroup::class)($c->user, $company(self::PARENT), 'Konsultbolaget med dotterföretag');

        foreach (array_column(self::HOLDINGS, 1) as $name) {
            app(ConfirmGroupMember::class)($c->user, $group->id, $company($name));
        }

        return $group;
    }
}
