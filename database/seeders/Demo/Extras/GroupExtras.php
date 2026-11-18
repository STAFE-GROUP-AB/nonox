<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\RecordHolding;
use App\Domain\Group\ControlBasis;
use App\Models\Company;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * The group structure of the manual (Koncern → Koncernstruktur): the
 * consulting company is the parent of the café (100 %) and the shop (80 %),
 * and the shop holds the salon (60 %). The demo owner owns every demo
 * company, so everything goes through the app's own actions as an
 * administrator of all of them.
 */
final class GroupExtras extends DemoExtra
{
    /** Holder, held, share of capital and votes (percent), acquisition date. */
    private const array HOLDINGS = [
        ['Konsultbolaget Exempel Demo AB', 'Café Hörnet Demo AB', '100', '2023-01-02'],
        ['Konsultbolaget Exempel Demo AB', 'Butik Lindgården Demo AB', '80', '2024-04-01'],
        ['Butik Lindgården Demo AB', 'Salong Saxen Demo AB', '60', '2025-02-03'],
    ];

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Koncernstruktur', function () use ($c): void {
            $companies = Company::query()->whereIn('name', ['Konsultbolaget Exempel Demo AB', 'Café Hörnet Demo AB', 'Butik Lindgården Demo AB', 'Salong Saxen Demo AB'])->get()->keyBy('name');

            if ($companies->count() < 4) {
                $c->warn('Koncernstrukturen hoppas över: demoföretagen finns inte alla.');

                return;
            }

            $user = $c->user;
            $company = fn (string $name): Company => $companies->get($name) ?? throw new \RuntimeException("Demoföretaget {$name} finns inte.");

            foreach (self::HOLDINGS as [$holder, $held, $share, $acquired]) {
                app(RecordHolding::class)($user, $company($holder), [
                    'held_org_number' => $company($held)->org_number,
                    'held_name' => $held,
                    'capital' => $share,
                    'voting' => $share,
                    'control_basis' => ControlBasis::Votes->value,
                    'acquired_on' => $acquired,
                ]);
            }

            $group = app(CreateCorporateGroup::class)($user, $company('Konsultbolaget Exempel Demo AB'), 'Konsultbolaget med dotterföretag');

            foreach (['Café Hörnet Demo AB', 'Butik Lindgården Demo AB', 'Salong Saxen Demo AB'] as $name) {
                app(ConfirmGroupMember::class)($user, $group->id, $company($name));
            }
        });
    }
}
