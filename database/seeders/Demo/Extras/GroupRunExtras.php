<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\MapGroupAccount;
use App\Actions\Group\RecordHolding;
use App\Domain\Group\ControlBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\CorporateGroup;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Koncern → Konsolidering (ADR-081 E24) for the manual: the consulting
 * company's group (the same structure as GroupExtras, which runs first and
 * is reused when it exists), one account map row for the café, and two runs
 * in the run log: the first half-year and the whole year. Everything goes
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
            app(ConsolidateGroup::class)($c->user, $group->id, ['from' => $start->toDateString(), 'to' => $start->addMonths(6)->subDay()->toDateString()]);
            app(ConsolidateGroup::class)($c->user, $group->id, ['from' => $start->toDateString(), 'to' => $c->fiscalYear->end_date->toDateString()]);
        });
    }

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
