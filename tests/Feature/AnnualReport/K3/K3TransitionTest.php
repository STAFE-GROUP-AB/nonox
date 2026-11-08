<?php

declare(strict_types=1);

use App\Actions\AnnualReport\BookK3Transition;
use App\Actions\AnnualReport\K3TransitionItems;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Actions\Assets\FixedAssets;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\LedgerVerifier;
use App\Domain\Accounting\StaleOpeningBalances;
use App\Domain\Accounting\VoucherSource;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\CashFlowStatement;
use App\Domain\AnnualReport\K3EquityStatement;
use App\Domain\AnnualReport\K3ForbiddenRestatement;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3Schedule;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Domain\Closing\DeferredTaxProposal;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\K3TransitionPage;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\K3Transition;
use App\Models\K3TransitionItem;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\S1Fixture;

/*
 * S1-P12 (ADR-059 A.2.8, A.4, A.5): the K2-to-K3 transition, proved against
 * fixture F-S1-b (comparatives not restated, transition date 2026-01-01) to
 * the öre, and the rules of chapter 35 (S1-R7, R7.1–R7.7).
 */

/** Öre as a person types it: "3600000,00". */
function transitionKronor(int $ore): string
{
    return sprintf('%d,%02d', intdiv($ore, 100), $ore % 100);
}

/**
 * Loads F-S1-b without its transition voucher, sets the 2026 policies,
 * closes 2025 and registers and splits the building on 2026-01-01.
 *
 * @param  array<string, string>  $policies  overrides of the fixture's 2026 policies
 */
function loadTransitionFixture(object $test, array $policies = [], bool $split = true): void
{
    $test->travelTo(CarbonImmutable::parse('2026-04-15 10:00:00'));
    $test->fixture = S1Fixture::load('f-s1-b', fn (array $voucher): bool => ($voucher['proposal_kind'] ?? null) !== 'k3_transition');
    $test->company = $test->fixture->company;
    $test->owner = $test->fixture->owner;
    $test->company->forceFill(['legal_form' => LegalForm::Ab])->save();
    CurrentCompany::set($test->company);
    $test->actingAs($test->owner);
    $test->k2 = $test->fixture->years[2025];
    $test->year = $test->fixture->years[2026];

    $data = json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-b/company.json')), true);
    $test->companyData = $data;

    foreach ([...$data['accounting_policies_2026'], ...$policies] as $key => $value) {
        app(SetAccountingPolicy::class)($test->owner, $test->company, $test->year->id, K3PolicyKey::from($key), $value);
    }

    $test->k2->forceFill(['status' => FiscalYearStatus::Closed, 'closed_at' => now()])->save();

    $building = $data['fixed_asset_register'][0];
    $assets = app(FixedAssets::class);
    $test->building = $assets->save($test->owner, $test->company, [
        'name' => $building['name'], 'category' => $building['category'], 'acquired_on' => $building['acquired_on'],
        'acquisition' => transitionKronor($building['acquisition_ore']), 'opening_accumulated' => transitionKronor($building['opening_accumulated_ore']),
        'life_months' => (string) $building['life_months'], 'depreciation_start' => $building['depreciation_start'],
        'asset_account' => (string) $building['asset_account'], 'accumulated_account' => (string) $building['accumulated_account'], 'expense_account' => (string) $building['expense_account'],
    ]);

    if ($split) {
        $test->building = $assets->split($test->owner, $test->company, $test->building->id, $building['split_on'], array_map(fn (array $component): array => [
            'name' => $component['name'], 'kind' => $component['kind'], 'cost' => transitionKronor($component['cost_ore']),
            'life_months' => (string) $component['remaining_life_months'], 'residual' => '', 'asset_account' => '',
        ], $building['components']));
    }
}

/** The fixture's transition items in the input format of K3TransitionItems. */
function enterTransitionItems(object $test): array
{
    $items = [];

    foreach ($test->companyData['transition_items'] as $item) {
        $input = ['kind' => $item['kind'], 'description' => $item['description']];

        if ($item['kind'] === 'DEFERRED_TAX') {
            $input += [
                'deferred_tax_kind' => $item['deferred_tax_kind'],
                'account_from' => (string) ($item['account_from'] ?? ''),
                'account_to' => (string) ($item['account_to'] ?? ''),
                'carrying' => isset($item['carrying_ore']) ? transitionKronor($item['carrying_ore']) : '',
                'tax_value' => isset($item['tax_value_ore']) ? transitionKronor($item['tax_value_ore']) : '',
                'recognised' => isset($item['recognised_ore']) ? transitionKronor($item['recognised_ore']) : '',
                'recognition_reason' => $item['recognition_reason'] ?? '',
                'relief' => $item['relief_35_27'] ?? false,
                'relief_reason' => $item['relief_reason'] ?? '',
            ];
        } else {
            $input += ['account' => (string) $item['account'], 'amount' => transitionKronor($item['amount_ore']), 'k3_reference' => $item['k3_reference']];
        }

        $items[$item['id']] = app(K3TransitionItems::class)->save($test->owner, $test->year, $input);
    }

    return $items;
}

/** @return list<array{0: int, 1: int, 2: int}> account, debit, credit per row, in order */
function voucherRowTriples(Voucher $voucher): array
{
    return $voucher->rows()->with('account')->orderBy('id')->get()
        ->map(fn ($row): array => [$row->account->number, $row->debit_ore->ore, $row->credit_ore->ore])->all();
}

describe('F-S1-b', function () {
    beforeEach(function () {
        loadTransitionFixture($this);
        $this->items = enterTransitionItems($this);
        $this->expected = $this->fixture->expected['transition'];
    });

    it('S1-R7.3 computes every transition item of F-S1-b to the öre', function () {
        $proposal = K3TransitionProposal::for($this->company, $this->year);
        $lines = collect($proposal->lines)->keyBy(fn (array $line): int => $line['item']->id);

        expect($proposal->blockers)->toBe([])
            ->and($proposal->rateBp)->toBe($this->expected['rate_bp'])
            ->and($proposal->transitionDate->toDateString())->toBe($this->expected['transition_date']);

        foreach ($this->expected['items'] as $want) {
            $line = $lines[$this->items[$want['id']]->id];

            expect($line['equity_ore'])->toBe($want['equity_effect_ore'], $want['id'])
                ->and($line['carrying_ore'])->toBe($want['carrying_ore'] ?? null, $want['id']);

            if ($want['kind'] === 'DEFERRED_TAX' && ! ($want['relief_35_27'] ?? false)) {
                expect($line['tax_value_ore'])->toBe($want['tax_value_ore'], $want['id'])
                    ->and($line['difference_ore'])->toBe($want['difference_ore'], $want['id'])
                    ->and($line['nature'])->toBe($want['nature'], $want['id'])
                    ->and($line['liability_ore'] + $line['asset_ore'])->toBe($want['deferred_tax_ore'], $want['id']);
            }

            if ($want['relief_35_27'] ?? false) {
                expect($line['nature'])->toBe(K3TransitionProposal::RELIEF)
                    ->and($line['rows'])->toBe([]);
            }
        }

        expect($proposal->equityEffectOre())->toBe($this->expected['equity_effect_ore'])
            ->and(array_map(fn (array $row): array => [$row['account'], max($row['amount'], 0), max(-$row['amount'], 0)], $proposal->voucherRows()))
            ->toBe(array_map(fn (array $row): array => [$row['account'], $row['debit_ore'], $row['credit_ore']], $this->expected['voucher']['rows']));
    });

    it('S1-R7 draws up the reconciliation sheet of F-S1-b', function () {
        $sheet = K3TransitionProposal::for($this->company, $this->year)->reconciliation();
        $want = $this->expected['reconciliation'];

        expect(array_map(fn (array $line): array => [$line['key'], $line['k2_ore'], $line['adjustment_ore'], $line['k3_ore']], $sheet['lines']))
            ->toEqualCanonicalizing(array_map(fn (array $line): array => [$line['key'], $line['k2_ore'], $line['adjustment_ore'], $line['k3_ore']], $want['lines']))
            ->and($sheet['equity_k2_ore'])->toBe($want['equity_k2_ore'])
            ->and($sheet['equity_k3_ore'])->toBe($want['equity_k3_ore'])
            ->and($sheet['total_k2_ore'])->toBe($want['balance_sheet_total_k2_ore'])
            ->and($sheet['total_k3_ore'])->toBe($want['balance_sheet_total_k3_ore']);
    });

    it('S1-R7.2 a: the component split at the transition date gives no equity difference and no voucher', function () {
        $want = $this->expected['components'];
        $proposal = K3TransitionProposal::for($this->company, $this->year);
        $building = $proposal->buildings[0];
        $components = FixedAsset::query()->where('parent_id', $this->building->id)->orderBy('id')->get();

        expect($proposal->buildings)->toHaveCount(1)
            ->and($building['status'])->toBe('split')
            ->and($building['carrying_before_ore'])->toBe($want['carrying_before_ore'])
            ->and($building['carrying_after_ore'])->toBe($want['carrying_after_ore'])
            ->and($building['carrying_after_ore'] - $building['carrying_before_ore'])->toBe($want['equity_difference_ore'])
            // No item comes from the split, and no reclassification voucher was booked for it.
            ->and(collect($proposal->voucherRows())->pluck('account')->intersect([1110, 1119])->all())->toBe([])
            ->and(Voucher::query()->where('fiscal_year_id', $this->year->id)->count())->toBe(0);

        foreach ($want['components'] as $index => $component) {
            $asset = $components[$index];

            expect($asset->name)->toBe($component['name'])
                ->and($asset->acquisition_ore->ore)->toBe($component['cost_ore'])
                ->and($asset->opening_accumulated_ore->ore)->toBe($component['accumulated_ore'])
                ->and($asset->bookValueOre())->toBe($component['carrying_ore'])
                ->and($asset->total_useful_life_months)->toBe($component['total_life_months'])
                ->and($asset->plan()->amountFor('2026-01', $asset->accumulatedOre()))->toBe($component['monthly_ore']);
        }

        expect($components->sum(fn (FixedAsset $asset): int => $asset->plan()->amountFor('2026-01', $asset->accumulatedOre())) * 12)->toBe($want['depreciation_2026_ore']);
    });

    it('S1-R7.1 S1-R7.5 lists the disclosures of F-S1-b and words the comparability note', function () {
        $proposal = K3TransitionProposal::for($this->company, $this->year);
        $disclosures = $proposal->disclosures();
        $want = $this->expected['disclosures'];

        expect($disclosures['first_time'])->toBe($want['first_time_k3'])
            ->and($disclosures['comparatives_restated'])->toBe(! $want['comparatives_not_restated'])
            ->and($disclosures['reliefs'])->toBe($want['reliefs_used'])
            ->and($disclosures['relief_items'])->toBe($want['relief_items'])
            ->and($disclosures['not_restated_35_30'])->toBe($want['items_not_restated_35_30'])
            ->and($disclosures['changed_principles'])->toEqualCanonicalizing($want['changed_principles'])
            ->and($disclosures['equity_effect_ore'])->toBe($want['equity_effect_ore']);

        $note = implode(' ', $proposal->comparabilityNote());

        expect($note)->toContain('första gången enligt Bokföringsnämndens allmänna råd BFNAR 2012:1')
            ->toContain('Jämförelsetalen för räkenskapsåret 2025 har inte räknats om')
            ->toContain('35.27')
            ->toContain('Inventarier anskaffade 2023')
            ->toContain(Money::ofOre(36_630_000)->format());
    });

    it('S1-R7.7 H7 books one K3_TRANSITION voucher on the first day against 2091, leaving IB and the K2 year untouched', function () {
        $k2Before = AnnualAccounts::for($this->k2);
        $k2Figures = [$k2Before->incomeStatement(), $k2Before->balanceSheet(), $k2Before->yearResult()];
        $openingBefore = OpeningBalance::query()->where('fiscal_year_id', $this->year->id)->orderBy('account_id')->get()->map(fn ($row): array => [$row->account_id, $row->amount_ore->ore])->all();
        $k2Vouchers = Voucher::query()->where('fiscal_year_id', $this->k2->id)->orderBy('id')->pluck('hash')->all();
        $proposal = K3TransitionProposal::for($this->company, $this->year);

        $transition = app(BookK3Transition::class)($this->owner, $this->year, $proposal->fingerprint());
        $voucher = Voucher::query()->findOrFail($transition->voucher_id);

        expect($voucher->source)->toBe(VoucherSource::K3Transition)
            ->and($voucher->voucher_date->toDateString())->toBe($this->expected['voucher']['date'])
            ->and($voucher->fiscal_year_id)->toBe($this->year->id)
            ->and(voucherRowTriples($voucher))->toBe(array_map(fn (array $row): array => [$row['account'], $row['debit_ore'], $row['credit_ore']], $this->expected['voucher']['rows']))
            ->and($transition->equity_effect_ore->ore)->toBe($this->expected['equity_effect_ore'])
            ->and($transition->rate_bp)->toBe(2060)
            ->and($transition->transition_date->toDateString())->toBe('2026-01-01')
            // IB stays equal to the previous UB (H7 a).
            ->and(OpeningBalance::query()->where('fiscal_year_id', $this->year->id)->orderBy('account_id')->get()->map(fn ($row): array => [$row->account_id, $row->amount_ore->ore])->all())->toBe($openingBefore)
            ->and(StaleOpeningBalances::computeForYear($this->company, $this->year))->toBeNull()
            // The K2 year: no voucher added or changed, the same annual accounts.
            ->and(Voucher::query()->where('fiscal_year_id', $this->k2->id)->orderBy('id')->pluck('hash')->all())->toBe($k2Vouchers);

        $k2After = AnnualAccounts::for($this->k2->refresh());

        expect([$k2After->incomeStatement(), $k2After->balanceSheet(), $k2After->yearResult()])->toBe($k2Figures)
            ->and($k2After->yearResult())->toBe($this->fixture->expected['last_k2_year']['arets_resultat'])
            ->and(K3TransitionItem::query()->whereNull('k3_transition_id')->count())->toBe(0)
            ->and(app(LedgerVerifier::class)->verify($this->company)['ok'])->toBeTrue();
    });

    it('S1-R7.7 books the transition only once, and freezes it', function () {
        $book = app(BookK3Transition::class);
        $transition = $book($this->owner, $this->year, K3TransitionProposal::for($this->company, $this->year)->fingerprint());

        expect(fn () => $book($this->owner, $this->year, K3TransitionProposal::for($this->company, $this->year)->fingerprint()))
            ->toThrow(ValidationException::class, 'redan bokförd');
        expect(fn () => app(K3TransitionItems::class)->save($this->owner, $this->year, ['kind' => 'PROVISION', 'description' => 'x', 'account' => '2220', 'amount' => '1']))
            ->toThrow(ValidationException::class, 'redan bokförd');
        expect(fn () => $this->items['T2']->refresh()->forceFill(['description' => 'Ändrad'])->save())->toThrow(LogicException::class);
        // Each raw statement in its own savepoint, so a refused one does not abort the test's transaction.
        expect(fn () => DB::transaction(fn () => DB::table('k3_transition_items')->where('id', $this->items['T2']->id)->update(['description' => 'Ändrad'])))->toThrow(Exception::class, 'cannot be changed');
        expect(fn () => DB::transaction(fn () => DB::table('k3_transitions')->where('id', $transition->id)->delete()))->toThrow(Exception::class, 'cannot be changed or removed');
        expect(K3Transition::query()->count())->toBe(1)
            ->and(Voucher::query()->where('source', VoucherSource::K3Transition->value)->count())->toBe(1);
    });

    it('refuses to book when the proposal changed since it was shown', function () {
        $seen = K3TransitionProposal::for($this->company, $this->year)->fingerprint();
        app(K3TransitionItems::class)->save($this->owner, $this->year, ['id' => $this->items['T2']->id, 'kind' => 'PROVISION', 'description' => 'Garantiåtaganden', 'account' => '2220', 'amount' => '160 000']);

        expect(fn () => app(BookK3Transition::class)($this->owner, $this->year, $seen))->toThrow(ValidationException::class, 'har ändrats');
        expect(K3Transition::query()->count())->toBe(0);
    });

    it('S1-R8.3 shows the transition in row b of the 2026 equity statement', function () {
        app(BookK3Transition::class)($this->owner, $this->year, K3TransitionProposal::for($this->company, $this->year)->fingerprint());
        $statement = K3EquityStatement::of(AnnualAccounts::for($this->year->refresh(), K3Schedule::class));
        $rows = collect($statement->rows())->keyBy('key');

        expect($statement->refusal())->toBeNull()
            ->and($rows['retroactive']['amounts']['retained'])->toBe($this->expected['equity_effect_ore'])
            ->and($rows['retroactive']['total'])->toBe($this->expected['equity_effect_ore'])
            ->and($rows['closing']['total'])->toBe($this->expected['reconciliation']['equity_k3_ore']);
    });

    it('S1-R2 refuses a cash flow statement of the first K3 year with the transition voucher named, never misprints it (follow-up S1-P10)', function () {
        app(BookK3Transition::class)($this->owner, $this->year, K3TransitionProposal::for($this->company, $this->year)->fingerprint());
        $statement = CashFlowStatement::of(AnnualAccounts::for($this->year->refresh(), K3Schedule::class), withPrevious: false);

        expect($statement->refusal())->not->toBeNull()
            ->and($statement->refusal())->toContain('A1');
    });

    it('S1-R4 measures the year\'s deferred tax change from the K3 opening balance after the transition', function () {
        app(BookK3Transition::class)($this->owner, $this->year, K3TransitionProposal::for($this->company, $this->year)->fingerprint());
        $proposal = DeferredTaxProposal::for($this->company, $this->year->refresh());

        expect($proposal->openingLiabilityOre)->toBe(24_720_000)
            ->and($proposal->openingAssetOre)->toBe(3_090_000)
            ->and($proposal->bookedLiabilityOre)->toBe(24_720_000);
    });

    it('lets the page show the proposal and book it after the confirmation', function () {
        $page = Livewire::test(K3TransitionPage::class, ['year' => $this->year->id])
            ->assertOk()
            ->assertSee('Beta')
            ->assertSee('Avstämning av eget kapital')
            ->assertSee(Money::ofOre(24_720_000)->format())
            ->assertSee('Bokföra övergången till K3 som en verifikation den 2026-01-01', false)
            ->assertSee('Inventarier anskaffade 2023');

        $page->call('confirm', K3TransitionProposal::for($this->company, $this->year)->fingerprint())->assertHasNoErrors();

        expect(K3Transition::query()->count())->toBe(1);
        Livewire::test(K3TransitionPage::class, ['year' => $this->year->id])->assertSee('Övergången är bokförd');
    });

    it('opens on the Swedish URL from the closing page', function () {
        $this->get(route('accounting.closing.k3Transition', ['ar' => $this->year->id]))
            ->assertOk()
            ->assertSee('Övergång till K3');

        expect(route('accounting.closing.k3Transition', absolute: false))->toBe('/bokslut/overgang-till-k3');
    });
});

describe('chapter 35 rules', function () {
    it('S1-R7.6 35.1: a company that applied K3 before and leaves K2 by choice may not use chapter 35 again', function () {
        loadTransitionFixture($this, ['k3_applied_before' => 'yes', 'left_k2_reason' => 'voluntary']);
        $proposal = K3TransitionProposal::for($this->company, $this->year);

        expect(implode(' ', $proposal->blockers))->toContain('35.1');
        expect(fn () => app(BookK3Transition::class)($this->owner, $this->year, $proposal->fingerprint()))->toThrow(ValidationException::class, '35.1');
    });

    it('S1-R7.6 BFNAR 2025:3 point 2: a company forced off K2 may use chapter 35 even after an earlier transition', function () {
        loadTransitionFixture($this, ['k3_applied_before' => 'yes', 'left_k2_reason' => 'forced_2025_2']);
        $old = FiscalYear::create(['start_date' => '2020-01-01', 'end_date' => '2020-12-31']);
        K3Transition::create(['fiscal_year_id' => $old->id, 'transition_date' => '2020-01-01', 'equity_effect_ore' => 0, 'confirmed_by' => $this->owner->id]);

        $proposal = K3TransitionProposal::for($this->company, $this->year);

        expect($proposal->blockers)->toBe([])
            ->and($proposal->chapter35Repeated)->toBeTrue();

        $transition = app(BookK3Transition::class)($this->owner, $this->year, $proposal->fingerprint());

        expect($transition->chapter_35_repeated)->toBeTrue();
    });

    it('S1-R7 35.1: an earlier booked transition blocks a company that leaves K2 by choice', function () {
        loadTransitionFixture($this, ['k3_applied_before' => 'no', 'left_k2_reason' => 'voluntary']);
        $old = FiscalYear::create(['start_date' => '2020-01-01', 'end_date' => '2020-12-31']);
        K3Transition::create(['fiscal_year_id' => $old->id, 'transition_date' => '2020-01-01', 'equity_effect_ore' => 0, 'confirmed_by' => $this->owner->id]);

        expect(implode(' ', K3TransitionProposal::for($this->company, $this->year)->blockers))->toContain('redan bokförd för räkenskapsåret som börjar 2020-01-01');
    });

    it('S1-R7.1 refuses restated comparatives (the `no` branch first)', function () {
        loadTransitionFixture($this, ['comparatives_restated_at_transition' => 'yes']);

        expect(implode(' ', K3TransitionProposal::for($this->company, $this->year)->blockers))->toContain('Omräknade jämförelsetal vid övergången stöds inte');
    });

    it('S1-R7.3 35.27: the relief needs a reason, and is disclosed', function () {
        loadTransitionFixture($this);
        $items = app(K3TransitionItems::class);
        $input = ['kind' => 'DEFERRED_TAX', 'deferred_tax_kind' => 'OTHER_ASSET', 'description' => 'Inventarier', 'account_from' => '1220', 'account_to' => '1229', 'relief' => true];

        expect(fn () => $items->save($this->owner, $this->year, $input))->toThrow(ValidationException::class, 'oskälig kostnad');

        $items->save($this->owner, $this->year, [...$input, 'relief_reason' => 'Underlaget finns inte kvar.']);
        $proposal = K3TransitionProposal::for($this->company, $this->year);

        expect($proposal->equityEffectOre())->toBe(0)
            ->and($proposal->disclosures()['reliefs'])->toBe(['K3 35.27'])
            ->and(implode(' ', $proposal->comparabilityNote()))->toContain('Lättnadsregeln i K3 punkt 35.27 har tillämpats');
    });

    it('S1-R7.2 refuses the retroactive restatements chapter 35 forbids', function (string $kind, string $rule) {
        loadTransitionFixture($this);

        expect(fn () => app(K3TransitionItems::class)->save($this->owner, $this->year, ['kind' => $kind, 'description' => 'x', 'account' => '1110', 'amount' => '1']))
            ->toThrow(ValidationException::class, str_replace('K3 ', 'K3 punkt ', $rule));
    })->with(array_map(fn (K3ForbiddenRestatement $case): array => [$case->value, $case->rule()], K3ForbiddenRestatement::cases()));

    it('S1-R7.2 refuses an adjustment of intangible or tangible fixed asset accounts', function () {
        loadTransitionFixture($this);
        $items = app(K3TransitionItems::class);

        expect(fn () => $items->save($this->owner, $this->year, ['kind' => 'OTHER', 'description' => 'Aktiverad utveckling', 'account' => '1010', 'amount' => '100 000', 'direction' => 'debit', 'k3_reference' => 'K3 18']))
            ->toThrow(ValidationException::class, '35.16');
        expect(fn () => $items->save($this->owner, $this->year, ['kind' => 'OTHER', 'description' => 'Byggnad', 'account' => '1119', 'amount' => '100 000', 'direction' => 'credit', 'k3_reference' => 'K3 17']))
            ->toThrow(ValidationException::class, '35.18');
        expect(fn () => $items->save($this->owner, $this->year, ['kind' => 'OTHER', 'description' => 'Periodiseringsfond', 'account' => '2125', 'amount' => '1', 'direction' => 'credit', 'k3_reference' => 'K3 29']))
            ->toThrow(ValidationException::class, '29.37');
        expect(K3TransitionItem::query()->count())->toBe(0);
    });

    it('S1-R7.2 a H4 blocks the transition until the building is split on the transition date', function () {
        loadTransitionFixture($this, split: false);

        expect(implode(' ', K3TransitionProposal::for($this->company, $this->year)->blockers))->toContain('inte uppdelad i komponenter')->toContain('35.18');

        $building = $this->companyData['fixed_asset_register'][0];
        app(FixedAssets::class)->split($this->owner, $this->company, $this->building->id, '2026-01-01', array_map(fn (array $component): array => [
            'name' => $component['name'], 'kind' => $component['kind'], 'cost' => transitionKronor($component['cost_ore']),
            'life_months' => (string) $component['remaining_life_months'], 'residual' => '', 'asset_account' => '',
        ], $building['components']));

        expect(K3TransitionProposal::for($this->company, $this->year)->blockers)->toBe([]);
    });

    it('blocks while the last K2 year is open or its closing balances differ from the opening balances', function () {
        loadTransitionFixture($this);
        $this->k2->forceFill(['status' => FiscalYearStatus::Open, 'closed_at' => null])->save();
        $account = Account::query()->where('number', 1930)->firstOrFail();
        OpeningBalance::query()->where('fiscal_year_id', $this->year->id)->where('account_id', $account->id)->update(['amount_ore' => 100]);

        $blockers = implode(' ', K3TransitionProposal::for($this->company, $this->year)->blockers);

        expect($blockers)->toContain('Stäng räkenskapsåret 2025 först')->toContain('stämmer inte med de utgående balanserna');
    });

    it('refuses a K2 year, a later K3 year and a company form S1 does not support', function () {
        loadTransitionFixture($this);

        expect(K3TransitionProposal::refusals($this->company, $this->k2))->not->toBe([]);

        $next = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        expect(implode(' ', K3TransitionProposal::refusals($this->company, $next->refresh())))->toContain('inte företagets första år med K3');

        $this->company->forceFill(['legal_form' => LegalForm::Hb])->save();
        expect(implode(' ', K3TransitionProposal::refusals($this->company->refresh(), $this->year)))->toContain('aktiebolag och ekonomiska föreningar');
        expect(fn () => app(K3TransitionItems::class)->save($this->owner, $this->year, ['kind' => 'PROVISION', 'description' => 'x', 'account' => '2220', 'amount' => '1']))
            ->toThrow(ValidationException::class);
    });

    it('lets only the roles that manage the closing enter and book the transition', function () {
        loadTransitionFixture($this);
        $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
        $auditor = Bookkeeping::member($this->company, 'auditor');

        expect(fn () => app(K3TransitionItems::class)->save($bookkeeper, $this->year, ['kind' => 'PROVISION', 'description' => 'x', 'account' => '2220', 'amount' => '1']))
            ->toThrow(AuthorizationException::class);
        expect(fn () => app(BookK3Transition::class)($auditor, $this->year, 'x'))->toThrow(AuthorizationException::class);

        $this->actingAs($auditor);
        Livewire::test(K3TransitionPage::class, ['year' => $this->year->id])->assertOk()->assertDontSee('Bekräfta och bokför övergången');
    });

    it('runs a fixed number of queries however many items there are', function () {
        loadTransitionFixture($this);
        $count = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            Livewire::test(K3TransitionPage::class, ['year' => $this->year->id])->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        enterTransitionItems($this);
        $count();
        $short = $count();

        foreach (range(1, 10) as $index) {
            app(K3TransitionItems::class)->save($this->owner, $this->year, ['kind' => 'PROVISION', 'description' => "Avsättning {$index}", 'account' => '2220', 'amount' => (string) (1000 * $index)]);
        }

        expect($count())->toBe($short);
    });

    it('is refused for a K2 year on the page with the reason', function () {
        loadTransitionFixture($this);

        Livewire::test(K3TransitionPage::class, ['year' => $this->k2->id])->assertOk()->assertSee('följer K2');
        expect($this->year->reporting_framework)->toBe(ReportingFramework::K3);
    });
});
