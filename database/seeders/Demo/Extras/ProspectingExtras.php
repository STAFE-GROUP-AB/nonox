<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Models\RegistryChange;
use App\Models\RegistryCompany;
use App\Models\RegistryImport;
use App\Models\User;
use App\Modules\Crm\Actions\AddProspects;
use App\Modules\Crm\Actions\CreateCallList;
use App\Modules\Crm\Actions\CreateRegistryNotices;
use App\Modules\Crm\Actions\WorkCallList;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\CallListItem;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\Demo\DemoIndustries;

/**
 * CRM stage 4 in the consultancy (ADR-047), for the manual's prospecting
 * pages: a small, made-up company register (never real data: every
 * organisation number is synthetic, 559999 6xxx, and every name ends in
 * "Exempel"), the consultancy's own customers in it so the look-alike
 * search has something to compare with, a call list for the seller with
 * a few calls logged, and a change in the register that has become an
 * "Ändring hos kunden" task.
 *
 * The register is instance data: it is written once, whichever demo
 * company runs first, and never touched by a real import.
 */
final class ProspectingExtras extends DemoExtra
{
    /**
     * Made-up register companies: serial, name, SNI 2025 code, town, legal form, employee class, county, registration date.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: string, 7: string}>
     */
    private const array REGISTER = [
        ['01', 'Kodkraft Exempel AB', '62201', 'STOCKHOLM', '49', 3, '01', '2018-04-11'],
        ['02', 'Molnbyrån Exempel AB', '62201', 'STOCKHOLM', '49', 4, '01', '2016-09-01'],
        ['03', 'Systemhuset Exempel AB', '62201', 'SOLNA', '49', 3, '01', '2021-02-15'],
        ['04', 'Datakonsulterna Exempel AB', '62201', 'UPPSALA', '49', 4, '03', '2012-11-30'],
        ['05', 'Integrationsfabriken Exempel AB', '62201', 'STOCKHOLM', '49', 5, '01', '2010-06-21'],
        ['06', 'Apputvecklarna Exempel AB', '62100', 'STOCKHOLM', '49', 2, '01', '2023-03-03'],
        ['07', 'Kodverkstaden Exempel HB', '62100', 'NACKA', '31', 2, '01', '2019-08-19'],
        ['08', 'Plattformen Exempel AB', '62100', 'GÖTEBORG', '49', 4, '14', '2015-01-12'],
        ['09', 'Driftpartner Exempel AB', '62202', 'STOCKHOLM', '49', 3, '01', '2017-05-05'],
        ['10', 'Säkerhetskonsult Exempel AB', '62900', 'SUNDBYBERG', '49', 3, '01', '2020-10-10'],
        ['11', 'Ledningsrådet Exempel AB', '70200', 'STOCKHOLM', '49', 2, '01', '2014-02-28'],
        ['12', 'Förändringsbyrån Exempel AB', '70200', 'UPPSALA', '49', 3, '03', '2022-01-17'],
        ['13', 'Siffrorna Exempel AB', '69201', 'STOCKHOLM', '49', 2, '01', '2011-12-01'],
        ['14', 'Byggteknik Exempel AB', '71121', 'VÄSTERÅS', '49', 4, '19', '2009-03-09'],
        ['15', 'Måleriet Exempel AB', '43341', 'STOCKHOLM', '49', 3, '01', '2013-07-07'],
        ['16', 'Krogen Exempel AB', '56110', 'STOCKHOLM', '49', 4, '01', '2019-04-01'],
        ['17', 'Webbstudion Exempel AB', '62100', 'MALMÖ', '49', 2, '12', '2024-05-20'],
        ['18', 'Analyshuset Exempel AB', '62201', 'LIDINGÖ', '49', 2, '01', '2025-09-02'],
        ['19', 'Konsultföreningen Exempel ek. för.', '62201', 'STOCKHOLM', '51', 2, '01', '2016-03-14'],
        ['20', 'Nätverkarna Exempel AB', '62202', 'TÄBY', '49', 3, '01', '2018-11-11'],
    ];

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        // The made-up register goes only into a local or test database, or one whose register is empty:
        // never mixed into a real register loaded from SCB and Bolagsverket.
        if (! app()->environment('local', 'testing') && RegistryCompany::query()->exists()) {
            return;
        }

        $this->register($c);
        $this->callList($c);
        $this->change($c);
    }

    /** The made-up register, with the consultancy's own customers and prospects in it. */
    private function register(DemoContext $c): void
    {
        $now = now();

        foreach (self::REGISTER as $index => [$serial, $name, $sni, $town, $form, $employees, $county, $registered]) {
            RegistryCompany::query()->updateOrCreate(['org_number' => (string) preg_replace('/\D/', '', DemoIndustries::orgNumber($serial, '559', '9996'))], [
                'name' => $name,
                'legal_form' => $form,
                'scb_status' => 1,
                'sni_code' => $sni,
                'sni_division' => substr($sni, 0, 2),
                'sni_codes' => [$sni],
                'address_line1' => 'Exempelgatan '.($index + 1),
                'postal_code' => '111'.str_pad((string) ($index + 20), 2, '0', STR_PAD_LEFT),
                'city' => $town,
                'county_code' => $county,
                'employee_class' => $employees,
                'registered_on' => $registered,
                'advertising_block' => $serial === '13',
                'sources' => ['bolagsverket-bulk', 'scb-api', 'scb-bulk'],
                'fetched_at' => $now,
                'bolagsverket_fetched_at' => $now,
            ]);
        }

        // The consultancy's customers and prospects, so the look-alike search and the card's register facts have them.
        foreach (Account::query()->whereNotNull('org_number')->get() as $account) {
            RegistryCompany::query()->updateOrCreate(['org_number' => (string) preg_replace('/\D/', '', (string) $account->org_number)], [
                'name' => $account->name,
                'legal_form' => '49',
                'scb_status' => 1,
                'sni_code' => '62201',
                'sni_division' => '62',
                'sni_codes' => ['62201'],
                'address_line1' => $account->address_line1,
                'postal_code' => $account->postal_code === null ? null : (string) preg_replace('/\D/', '', $account->postal_code),
                'city' => mb_strtoupper((string) ($account->city ?? 'Stockholm')),
                'county_code' => '01',
                'employee_class' => 3,
                'registered_on' => '2015-06-01',
                'sources' => ['bolagsverket-bulk', 'scb-api', 'scb-bulk'],
                'fetched_at' => $now,
                'bolagsverket_fetched_at' => $now,
            ]);
        }

        foreach (['scb-bulk', 'bolagsverket-bulk', 'scb-api'] as $source) {
            RegistryImport::query()->firstOrCreate(['source' => $source, 'version' => 'demo'], ['status' => RegistryImport::DONE, 'started_at' => $now, 'finished_at' => $now]);
        }
    }

    /** The seller's call list of IT companies in the Stockholm region, three calls in. */
    private function callList(DemoContext $c): void
    {
        $seller = User::query()->where('email', CrmExtras::SELLER['email'])->first();
        $orgs = array_map(fn (array $row): string => (string) preg_replace('/\D/', '', DemoIndustries::orgNumber($row[0], '559', '9996')), array_slice(self::REGISTER, 0, 7));
        $added = app(AddProspects::class)($c->user, $c->company, $orgs, $seller?->id);

        // Phone numbers in the range PTS keeps for fiction and examples (08-465 004 00 to 499).
        foreach (Account::query()->whereKey($added['added'])->orderBy('id')->get() as $index => $account) {
            $account->forceFill(['phone' => '08-465 004 '.(60 + $index)])->save();
        }

        $list = app(CreateCallList::class)($c->user, $c->company, ['name' => 'IT-bolag i Stockholm och Uppsala', 'assigned_to' => $seller?->id], [...$added['added'], ...$added['existing']], ['source' => 'register', 'filters' => ['sni' => '62', 'county' => '01']])['list'];
        $work = app(WorkCallList::class);
        $item = fn (): ?CallListItem => CallListItem::query()->where('call_list_id', $list->id)->where('status', CallListItem::PENDING)->orderBy('position')->first();

        $first = $item();
        if ($first !== null) {
            $work->call($c->user, $c->company, $first, ['outcome' => 'REACHED', 'note' => 'Intresserade av en säkerhetsgranskning efter nyår.', 'next_type' => 'MEETING', 'next_subject' => 'Möte om säkerhetsgranskning', 'next_at' => $c->today->addDays(6)->setTime(10, 0)->format('Y-m-d\TH:i')]);
        }

        $second = $item();
        if ($second !== null) {
            $work->call($c->user, $c->company, $second, ['outcome' => 'NO_ANSWER']);
        }

        $third = $item();
        if ($third !== null) {
            $work->call($c->user, $c->company, $third, ['outcome' => 'LEFT_MESSAGE', 'note' => 'Bad dem ringa tillbaka.']);
        }
    }

    /** A move at the biggest customer, reported as a task. */
    private function change(DemoContext $c): void
    {
        $account = Account::query()->where('name', 'Kunden Exempel AB')->whereNotNull('org_number')->first();

        if (! $account instanceof Account) {
            return;
        }

        RegistryChange::query()->create([
            'org_number' => (string) preg_replace('/\D/', '', (string) $account->org_number),
            'field' => 'address_line1',
            'old_value' => $account->address_line1 ?? 'Exempelgatan 1',
            'new_value' => 'Nya Exempelvägen 12',
            'source' => 'scb-bulk',
            'detected_at' => now(),
        ]);

        app(CreateRegistryNotices::class)($c->company);
    }
}
