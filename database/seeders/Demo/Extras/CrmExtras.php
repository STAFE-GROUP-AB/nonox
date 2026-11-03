<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Models\Article;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\MoveDeal;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Actions\SaveDeal;
use App\Modules\Crm\Actions\SaveGoal;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\ModuleManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\Demo\DemoIndustries;
use Illuminate\Validation\ValidationException;

/**
 * CRM in the consultancy (ADR-043), for the manual's CRM pages and the
 * landing page: the module on, so the customers from the books become
 * accounts with their key figures, contact people with roles and a legal
 * basis, prospects at different stages (one lost), and activities spread
 * over the last months with open next steps: one overdue, two today and
 * some coming. The consultancy hour gets an internal cost, so the
 * contribution margin shows, built on part of the sales.
 *
 * Stage 2: a made-up seller (Säljare), a dozen deals across the pipeline's
 * stages with their next steps, some won and some lost with a reason and a
 * win-back date, and this month's goals for the seller and the owner.
 *
 * Every name, number and address is made up: the organisation numbers are
 * synthetic (DemoIndustries::orgNumber), the e-mail addresses end in .test
 * and the phone numbers are in the ranges PTS keeps for fiction and examples
 * (08-465 004 00 to 499 and 070-174 06 05 to 99).
 */
final class CrmExtras extends DemoExtra
{
    /**
     * Prospects: name, serial for the organisation number, city, source, tags, people (name, title, role, legal basis).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: list<array{0: string, 1: string, 2: string, 3: string}>}>
     */
    private const array PROSPECTS = [
        ['Fastighetsbolaget Exempel AB', '41', 'Uppsala', 'Mässa', 'fastighet, varm', [['Lena Lund', 'Förvaltningschef', 'DECISION_MAKER', 'LEGITIMATE_INTEREST'], ['Omar Ek', 'IT-ansvarig', 'INFLUENCER', 'LEGITIMATE_INTEREST']]],
        ['Logistikhuset Exempel AB', '42', 'Göteborg', 'Tips från kund', 'logistik', [['Sara Berg', 'Inköpschef', 'DECISION_MAKER', 'LEGITIMATE_INTEREST']]],
        ['Tandvården Exempel AB', '43', 'Stockholm', 'Webben', 'vård', [['Per Holm', 'Klinikchef', 'DECISION_MAKER', 'CONSENT']]],
        ['Byggvaror Exempel AB', '44', 'Västerås', 'Mässa', 'bygg', [['Eva Sand', 'VD', 'DECISION_MAKER', 'LEGITIMATE_INTEREST']]],
    ];

    /**
     * People at the customers from the books: customer name => list of [name, title, role, legal basis].
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: string}>>
     */
    private const array CUSTOMER_PEOPLE = [
        'Kunden Exempel AB' => [['Karin Exempelsson', 'VD', 'DECISION_MAKER', 'AGREEMENT'], ['Johan Dahl', 'Ekonomichef', 'INVOICE_RECIPIENT', 'AGREEMENT'], ['Mia Strand', 'Projektledare', 'AMBASSADOR', 'AGREEMENT']],
        'Mjukvaruhuset Exempel AB' => [['Ali Nord', 'Teknisk chef', 'DECISION_MAKER', 'AGREEMENT'], ['Ida Viklund', 'Utvecklare', 'USER', 'LEGITIMATE_INTEREST']],
    ];

    /** The made-up seller, with the role Säljare. */
    public const array SELLER = ['name' => 'Oskar Lind', 'email' => 'oskar.lind@example.test'];

    /**
     * Open deals: account name, title, value in kronor, stage index, days ago created, days to close, the seller's.
     *
     * @var list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: bool}>
     */
    private const array OPEN_DEALS = [
        ['Fastighetsbolaget Exempel AB', 'Nytt förvaltningssystem', 380_000, 2, 30, 20, false],
        ['Fastighetsbolaget Exempel AB', 'Support och drift 2027', 96_000, 0, 5, 75, false],
        ['Logistikhuset Exempel AB', 'Lagerintegration', 145_000, 1, 12, 40, true],
        ['Tandvården Exempel AB', 'Bokningssystem för kliniken', 220_000, 1, 25, -3, true],
        ['Kunden Exempel AB', 'Ramavtal 2027', 640_000, 3, 60, 14, false],
        ['Kunden Exempel AB', 'Utbildning för projektledare', 48_000, 2, 9, 30, true],
        ['Mjukvaruhuset Exempel AB', 'Säkerhetsgranskning', 120_000, 0, 3, 60, true],
        ['Mjukvaruhuset Exempel AB', 'Fler konsulttimmar efter sommaren', 260_000, 3, 40, 10, false],
    ];

    /** The next number in the range PTS keeps for fiction and examples (070-174 06 05 to 070-174 06 99). */
    private int $mobiles = 0;

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        // The consultancy hour's internal cost, so the key figures show a contribution margin.
        Article::query()->where('number', 'KONS')->update(['purchase_price_ore' => 62_000]);

        app(ModuleManager::class)->enable(CrmModule::KEY, $c->company, $c->user);

        $this->customers($c);
        $this->prospects($c);
        $this->deals($c);
    }

    /** CRM stage 2: the seller, deals across the pipeline, won and lost ones, and the month's goals. */
    private function deals(DemoContext $c): void
    {
        $seller = User::query()->firstOrCreate(['email' => self::SELLER['email']], ['name' => self::SELLER['name'], 'email_verified_at' => now()]);
        $c->company->users()->syncWithoutDetaching([$seller->id => ['role' => 'sales']]);
        $c->company->unsetRelation('users');

        $pipeline = Pipeline::query()->where('is_default', true)->first();

        if ($pipeline === null) {
            return;
        }

        $stages = $pipeline->stages()->get()->values();
        $account = fn (string $name): ?Account => Account::query()->where('name', $name)->first();

        foreach (self::OPEN_DEALS as [$name, $title, $kronor, $stage, $daysAgo, $closeIn, $sellers]) {
            $target = $account($name);

            if ($target instanceof Account) {
                $this->openDeal($c, $target, $title, $kronor, $stages->slice(0, $stage + 1)->values()->all(), $daysAgo, $closeIn, $sellers ? $seller : $c->user);
            }
        }

        // Won and lost deals over the last months: the win rate, the sales cycle and the forecast's won column.
        $won = [
            ['Kunden Exempel AB', 'Workshop om arkitektur', 85_000, 70, 45, false],
            ['Mjukvaruhuset Exempel AB', 'Kodgranskning', 64_000, 50, 18, true],
            ['Kunden Exempel AB', 'Förlängning av uppdraget', 310_000, 35, 4, true],
        ];

        foreach ($won as [$name, $title, $kronor, $createdAgo, $wonAgo, $sellers]) {
            $target = $account($name);

            if ($target === null) {
                continue;
            }

            $deal = null;
            $this->at($c, $createdAgo, function () use ($c, $target, $title, $kronor, $sellers, $seller, $stages, &$deal): void {
                $deal = app(SaveDeal::class)($c->user, $c->company, ['account_id' => $target->id, 'title' => $title, 'value_ore' => $kronor * 100, 'owner_id' => $sellers ? $seller->id : $c->user->id, 'stage_id' => $stages[2]->id ?? null, 'next_step' => ['type' => 'TASK', 'subject' => 'Skicka offert']]);
            });
            $this->at($c, $wonAgo, fn () => $deal === null ? null : app(WinDeal::class)($c->user, $c->company, $deal->fresh() ?? $deal));
        }

        $reasons = LossReason::query()->pluck('id', 'name');
        $lost = [
            ['Byggvaror Exempel AB', 'Webbutik', 175_000, 'Valde en annan leverantör', 40, 'Valde en leverantör de redan har.'],
            ['Logistikhuset Exempel AB', 'Ruttplanering', 90_000, 'Fel tidpunkt', 20, 'Vill ta upp det igen efter årsskiftet.'],
        ];

        foreach ($lost as [$name, $title, $kronor, $reason, $lostAgo, $note]) {
            $target = $account($name);

            if ($target === null || ! isset($reasons[$reason])) {
                continue;
            }

            $deal = null;
            $this->at($c, $lostAgo + 25, function () use ($c, $target, $title, $kronor, &$deal): void {
                $deal = app(SaveDeal::class)($c->user, $c->company, ['account_id' => $target->id, 'title' => $title, 'value_ore' => $kronor * 100, 'next_step' => ['type' => 'CALL', 'subject' => 'Följ upp']]);
            });
            $this->at($c, $lostAgo, fn () => $deal === null ? null : app(LoseDeal::class)($c->user, $c->company, $deal->fresh() ?? $deal, ['loss_reason_id' => $reasons[$reason], 'loss_note' => $note]));
        }

        $month = $c->today->format('Y-m');

        foreach ([[$seller, 'MEETINGS', '8'], [$seller, 'QUOTES', '4'], [$seller, 'ORDER_VALUE', '400 000'], [$c->user, 'ORDER_VALUE', '600 000']] as [$user, $metric, $target]) {
            app(SaveGoal::class)($c->user, $c->company, ['user_id' => $user->id, 'month' => $month, 'metric' => $metric, 'target' => $target]);
        }

        // Meetings the seller has held this month, for the goal's progress.
        $this->logAt($c, 6, ['account_id' => $account('Logistikhuset Exempel AB')?->id, 'type' => 'MEETING', 'subject' => 'Behovsanalys', 'assigned_to' => $seller->id, 'done' => true]);
        $this->logAt($c, 2, ['account_id' => $account('Tandvården Exempel AB')?->id, 'type' => 'MEETING', 'subject' => 'Visning av förslaget', 'assigned_to' => $seller->id, 'done' => true]);
    }

    private function customers(DemoContext $c): void
    {
        $phones = ['08-465 004 10', '08-465 004 11', '08-465 004 12', '08-465 004 13', '08-465 004 14'];
        $n = 0;

        foreach (Customer::query()->orderBy('number')->get() as $customer) {
            $account = Account::query()->where('customer_id', $customer->id)->first();

            if ($account === null) {
                continue;
            }

            app(SaveAccount::class)($c->user, $c->company, [
                ...$account->only(['name', 'org_number', 'email', 'address_line1', 'postal_code', 'city', 'country']),
                'phone' => $customer->phone ?? $phones[$n % count($phones)],
                'owner_id' => $c->user->id,
                'source' => $n === 0 ? 'Rekommendation' : 'Nätverk',
                'sni_code' => '62010',
                'tags' => $n === 0 ? ['ramavtal', 'stockholm'] : ['it'],
            ], $account);
            $n++;

            $people = $this->people($c, $account, self::CUSTOMER_PEOPLE[$customer->name] ?? [['Kim Exempel', 'Inköpare', 'DECISION_MAKER', 'AGREEMENT']]);
            $first = $people[0] ?? null;

            $this->logAt($c, 75, ['account_id' => $account->id, 'person_id' => $first, 'type' => 'MEETING', 'subject' => 'Uppstart av uppdraget', 'body' => 'Gick igenom målen för året och vem som godkänner tiden.', 'done' => true]);
            $this->logAt($c, 40, ['account_id' => $account->id, 'person_id' => $first, 'type' => 'CALL', 'subject' => 'Avstämning', 'call_outcome' => 'REACHED', 'body' => 'Nöjda med leveransen. Vill diskutera fler timmar efter sommaren.', 'done' => true]);
            $this->logAt($c, 12, ['account_id' => $account->id, 'type' => 'NOTE', 'subject' => 'Anteckning', 'body' => 'Budgeten för nästa år beslutas i styrelsen i december.']);
            $this->logAt($c, 0, ['account_id' => $account->id, 'person_id' => $first, 'type' => 'TASK', 'subject' => 'Skicka förslag på ramavtal', 'due_at' => $c->today->addDays($n === 1 ? 0 : 6)->setTime(15, 0)->format('Y-m-d\TH:i')]);
        }
    }

    private function prospects(DemoContext $c): void
    {
        foreach (self::PROSPECTS as $index => [$name, $serial, $city, $source, $tags, $people]) {
            $account = app(SaveAccount::class)($c->user, $c->company, [
                'name' => $name,
                'org_number' => DemoIndustries::orgNumber($serial),
                'city' => $city,
                'phone' => '08-465 004 2'.$index,
                'email' => 'info@'.strtolower(explode(' ', $name)[0]).'.test',
                'source' => $source,
                'tags' => $tags,
                'owner_id' => $c->user->id,
            ]);
            $ids = $this->people($c, $account, $people);
            $person = $ids[0] ?? null;

            match ($index) {
                0 => $this->hot($c, $account, $person),
                1 => $this->early($c, $account, $person),
                2 => $this->overdue($c, $account, $person),
                default => $this->lost($c, $account, $person),
            };
        }
    }

    private function hot(DemoContext $c, Account $account, ?int $person): void
    {
        $this->logAt($c, 30, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'CALL', 'subject' => 'Första samtalet', 'call_outcome' => 'REACHED', 'body' => 'Har problem med sitt fastighetssystem. Vill träffas.', 'done' => true]);
        $this->logAt($c, 14, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'MEETING', 'subject' => 'Möte på plats', 'body' => 'Visade hur vi har löst samma sak hos en annan kund. Vill ha offert.', 'done' => true]);
        $this->logAt($c, 3, ['account_id' => $account->id, 'type' => 'EMAIL', 'subject' => 'Skickade underlag', 'body' => 'Referenser och en grov tidsplan.', 'done' => true]);
        $this->logAt($c, 0, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'CALL', 'subject' => 'Följ upp underlaget', 'due_at' => $c->today->setTime(10, 30)->format('Y-m-d\TH:i')]);
        $this->logAt($c, 0, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'MEETING', 'subject' => 'Genomgång av offerten', 'due_at' => $c->today->addDays(8)->setTime(13, 0)->format('Y-m-d\TH:i')]);
    }

    private function early(DemoContext $c, Account $account, ?int $person): void
    {
        $this->logAt($c, 6, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'CALL', 'subject' => 'Kalla samtalet', 'call_outcome' => 'LEFT_MESSAGE', 'done' => true]);
        $this->logAt($c, 0, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'CALL', 'subject' => 'Ring igen', 'due_at' => $c->today->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i')]);
    }

    private function overdue(DemoContext $c, Account $account, ?int $person): void
    {
        $this->logAt($c, 20, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'MEETING', 'subject' => 'Digitalt möte', 'body' => 'Intresserade, men vill vänta tills den nya kliniken öppnat.', 'done' => true]);
        $this->logAt($c, 20, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'TASK', 'subject' => 'Hör av dig om tidsplanen', 'due_at' => $c->today->subDays(3)->setTime(10, 0)->format('Y-m-d\TH:i')]);
    }

    private function lost(DemoContext $c, Account $account, ?int $person): void
    {
        $this->logAt($c, 50, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'MEETING', 'subject' => 'Presentation', 'done' => true]);
        $this->logAt($c, 35, ['account_id' => $account->id, 'person_id' => $person, 'type' => 'CALL', 'subject' => 'Besked', 'call_outcome' => 'REACHED', 'body' => 'Valde en leverantör de redan har.', 'done' => true]);

        $this->at($c, 35, fn () => app(SetAccountStatus::class)($c->user, $c->company, $account->fresh() ?? $account, 'LOST', 'Valde en befintlig leverantör'));
    }

    /**
     * An open deal created $daysAgo days ago and moved through $path, the
     * stages up to the one it is in now, three days apart.
     *
     * @param  array<int, PipelineStage>  $path
     */
    private function openDeal(DemoContext $c, Account $account, string $title, int $kronor, array $path, int $daysAgo, int $closeIn, User $owner): void
    {
        $deal = null;
        $late = count($path) >= 3;

        $this->at($c, $daysAgo, function () use ($c, $account, $title, $kronor, $closeIn, $owner, $late, &$deal): void {
            $deal = app(SaveDeal::class)($c->user, $c->company, [
                'account_id' => $account->id,
                'title' => $title,
                'value_ore' => $kronor * 100,
                'expected_close_date' => $c->today->addDays($closeIn)->toDateString(),
                'owner_id' => $owner->id,
                'next_step' => [
                    'type' => $late ? 'MEETING' : 'CALL',
                    'subject' => $late ? 'Gå igenom offerten' : 'Boka ett möte',
                    // A deal past its close date has an overdue step, so the board shows red.
                    'due_at' => $c->today->addDays($closeIn < 0 ? -2 : min(7, max(1, $closeIn)))->setTime(10, 0)->format('Y-m-d\TH:i'),
                ],
            ]);
        });

        foreach (array_slice(array_values($path), 1) as $i => $stage) {
            $this->at($c, max(0, $daysAgo - ($i + 1) * 3), fn () => $deal === null ? null : app(MoveDeal::class)($c->user, $c->company, $deal->fresh() ?? $deal, $stage));
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $people
     * @return list<int> the people's ids
     */
    private function people(DemoContext $c, Account $account, array $people): array
    {
        $ids = [];

        foreach ($people as $i => [$name, $title, $role, $basis]) {
            $handle = strtolower(str_replace(['å', 'ä', 'ö', ' '], ['a', 'a', 'o', '.'], $name));

            try {
                $person = app(SavePerson::class)($c->user, $c->company, [
                    'name' => $name,
                    'title' => $title,
                    'email' => "{$handle}@exempel.test",
                    'mobile' => '070-174 06 '.(10 + $this->mobiles++),
                    'legal_basis' => $basis,
                    'consent_at' => $basis === 'CONSENT' ? $c->today->subMonths(2)->toDateString() : null,
                ]);
                app(LinkPerson::class)($c->user, $c->company, $account, $person, PersonRole::from($role));
                $ids[] = $person->id;
            } catch (ValidationException $exception) {
                $c->warn(collect($exception->errors())->flatten()->implode(' '));
            }
        }

        return $ids;
    }

    /** @param  array<string, mixed>  $input */
    private function logAt(DemoContext $c, int $daysAgo, array $input): void
    {
        $this->at($c, $daysAgo, fn () => app(LogActivity::class)($c->user, $c->company, $input));
    }

    /**
     * Runs $step as if it happened $daysAgo days before today, during office hours.
     *
     * @param  callable(): mixed  $step
     */
    private function at(DemoContext $c, int $daysAgo, callable $step): void
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow(CarbonImmutable::parse($c->today->subDays($daysAgo)->toDateString().' 09:15:00', 'Europe/Stockholm'));

        try {
            $step();
        } catch (ValidationException $exception) {
            $c->warn(collect($exception->errors())->flatten()->implode(' '));
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
