<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\AnnualReport\SavePriorYearSizeFigures;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\CashFlowPrintedFigures;
use App\Domain\AnnualReport\CashFlowStatement;
use App\Domain\AnnualReport\CashFlowStatementRefused;
use App\Domain\AnnualReport\K2Schedule;
use App\Domain\AnnualReport\K3CompanySize;
use App\Domain\AnnualReport\K3Policies;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3Schedule;
use App\Domain\AnnualReport\K3SizeClass;
use App\Domain\AnnualReport\PrintedFigures;
use App\Domain\Company\LegalForm;
use App\Models\Account;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\S1Fixture;

/*
 * S1-P8 (ADR-059 A.4): the K3 cash flow statement, indirect method (K3 7.9),
 * from F-S1-a to the öre; the reconciliation with likvida medel; the block on
 * an account no category covers (spec Behaviour 4); the policy and size gate
 * (S1-R2, ÅRL 2 kap. 1 § andra stycket) with F-S1-c; the printed figures.
 */

/**
 * Posts a balanced voucher in the fixture company: rows as [account, debit öre, credit öre].
 *
 * @param  list<array{0: int, 1: int, 2: int}>  $rows
 */
function postCashFlowVoucher(S1Fixture $fixture, string $date, string $description, array $rows): Voucher
{
    foreach ($rows as [$number]) {
        if (! Account::query()->where('number', $number)->exists()) {
            Account::create(['number' => $number, 'name' => "Konto {$number}", 'type' => AccountType::forNumber($number), 'is_active' => true]);
        }
    }

    $draft = app(SaveDraftVoucher::class)($fixture->owner, $fixture->company, [
        'voucher_date' => $date,
        'description' => $description,
        'series' => 'A',
        'rows' => array_map(fn (array $row): array => ['account' => $row[0], 'debit_ore' => $row[1], 'credit_ore' => $row[2]], $rows),
    ]);

    return app(PostVoucher::class)($fixture->owner, $draft);
}

function cashFlow(FiscalYear $year): CashFlowStatement
{
    return CashFlowStatement::of(AnnualAccounts::for($year, K3Schedule::class));
}

/**
 * The fixture's figures for a year: every key with an integer amount.
 *
 * @param  array<string, mixed>  $expected
 * @return array<string, int>
 */
function expectedCashFlow(array $expected): array
{
    return array_filter($expected, fn (mixed $value, string $key): bool => is_int($value) && ! str_starts_with($key, '_'), ARRAY_FILTER_USE_BOTH);
}

function setCashFlowPolicy(S1Fixture $fixture, FiscalYear $year, string $value): void
{
    app(SetAccountingPolicy::class)($fixture->owner->fresh(), $fixture->company, $year->id, K3PolicyKey::CashFlowStatement, $value, null);
}

describe('F-S1-a', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
        $this->fixture = S1Fixture::load('f-s1-a');
        CurrentCompany::set($this->fixture->company);
        $this->actingAs($this->fixture->owner);
    });

    it('S1-R2.1 gives F-S1-a\'s 2025 and 2026 cash flow statements to the öre, line for line', function () {
        foreach ([2025, 2026] as $year) {
            $statement = cashFlow($this->fixture->years[$year]);
            $expected = expectedCashFlow($this->fixture->expected['cash_flow_statement'][(string) $year]);

            expect($statement->refusal())->toBeNull("{$year} refusal");

            foreach ($expected as $key => $amount) {
                // F-S1-a states interest paid as a positive amount although its own convention is
                // "inflow positive, outflow negative"; the statement keeps one sign convention
                // (reported to the Fixture author, S1-P8 review P3).
                $amount = $key === 'tillaggsupplysning_betald_ranta' ? -$amount : $amount;

                expect($statement->amounts())->toHaveKey($key)
                    ->and($statement->amounts()[$key])->toBe($amount, "{$year} {$key}");
            }

            // Every line the statement has beyond the fixture's is zero for F-S1-a.
            foreach (array_diff_key($statement->amounts(), $expected) as $key => $amount) {
                expect($amount)->toBe(0, "{$year} {$key}");
            }
        }
    });

    it('S1-R2.1 reconciles: the three activities add up to the change in likvida medel (1900–1999), to the öre', function () {
        foreach ([2025, 2026] as $year) {
            $accounts = AnnualAccounts::for($this->fixture->years[$year], K3Schedule::class);
            $amounts = CashFlowStatement::of($accounts)->amounts();

            expect($amounts['kassaflode_fran_den_lopande_verksamheten'] + $amounts['kassaflode_fran_investeringsverksamheten'] + $amounts['kassaflode_fran_finansieringsverksamheten'])->toBe($amounts['arets_kassaflode'])
                ->and($amounts['arets_kassaflode'])->toBe($accounts->closingOf(1900, 1999) - $accounts->openingOf(1900, 1999))
                ->and($amounts['likvida_medel_vid_arets_slut'])->toBe($accounts->closingOf(1900, 1999));
        }
    });

    it('S1-R2.1 shows the rows in K3 order, tagged with their K3 point, with the previous year beside them', function () {
        $rows = collect(cashFlow($this->fixture->years[2026])->rows())->keyBy('key');

        expect($rows->keys()->all())->toBe([
            'resultat_efter_finansiella_poster', 'justering_avskrivningar', 'betald_inkomstskatt',
            'kassaflode_fore_forandring_av_rorelsekapital', 'forandring_varulager', 'forandring_rorelsefordringar',
            'forandring_rorelseskulder', 'kassaflode_fran_den_lopande_verksamheten',
            'forvarv_av_materiella_anlaggningstillgangar', 'kassaflode_fran_investeringsverksamheten',
            'amortering_av_lan', 'utbetald_utdelning', 'kassaflode_fran_finansieringsverksamheten',
            'arets_kassaflode', 'likvida_medel_vid_arets_borjan', 'likvida_medel_vid_arets_slut',
            'tillaggsupplysning_betald_ranta',
        ])
            ->and($rows['betald_inkomstskatt'])->toMatchArray(['section' => 'operating', 'point' => '7.17', 'amount' => -78_982_518, 'previous' => -76_000_000])
            ->and($rows['utbetald_utdelning'])->toMatchArray(['section' => 'financing', 'point' => '7.16', 'amount' => -150_000_000, 'previous' => -120_000_000])
            ->and($rows['justering_avskrivningar']['point'])->toBe('7.9 a')
            ->and($rows['forandring_rorelseskulder']['point'])->toBe('7.9 b')
            ->and($rows['forvarv_av_materiella_anlaggningstillgangar']['point'])->toBe('7.6, 7.11')
            ->and($rows['amortering_av_lan']['point'])->toBe('7.7, 7.11')
            ->and($rows['forvarv_av_materiella_anlaggningstillgangar']['previous'])->toBe(0)
            ->and($rows['likvida_medel_vid_arets_borjan']['amount'])->toBe(398_204_000)
            ->and($rows['likvida_medel_vid_arets_borjan']['previous'])->toBe(300_000_000)
            ->and($rows['tillaggsupplysning_betald_ranta'])->toMatchArray(['amount' => -41_400_000, 'previous' => -46_200_000, 'section' => 'interest_dividends', 'point' => '7.14'])
            ->and($rows->pluck('label')->filter(fn (string $label): bool => str_contains(mb_strtolower($label), 'övrig'))->all())->toBe([]);

        // The first year in the books has no previous year beside it.
        expect(collect(cashFlow($this->fixture->years[2025])->rows())->pluck('previous')->unique()->all())->toBe([null]);
    });

    it('S1-R2.1 blocks an account no category covers, lists it, and never shows an "övrigt" row (spec Behaviour 4)', function () {
        postCashFlowVoucher($this->fixture, '2026-06-30', 'Lån från dotterföretag', [[1930, 10_000_000, 0], [2865, 0, 10_000_000]]);
        postCashFlowVoucher($this->fixture, '2026-06-30', 'Okänt internt konto', [[1930, 500_000, 0], [9100, 0, 500_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain('2865')
            ->and($statement->refusal())->toContain('9100')
            ->and($statement->refusal())->toContain('inte klassificerat')
            ->and($statement->unclassifiedAccounts())->toBe([2865, 9100])
            ->and(fn () => $statement->rows())->toThrow(CashFlowStatementRefused::class)
            ->and(fn () => $statement->amounts())->toThrow(CashFlowStatementRefused::class);

        try {
            $statement->rows();
        } catch (CashFlowStatementRefused $refused) {
            expect($refused->accounts)->toBe([2865, 9100]);
        }
    });

    it('S1-R2.1 blocks a disposal of a fixed asset, whose proceeds cannot yet be split from the gain (K3 7.9 a)', function () {
        postCashFlowVoucher($this->fixture, '2026-09-30', 'Försäljning av maskin', [[1930, 30_000_000, 0], [1219, 20_000_000, 0], [1210, 0, 40_000_000], [3973, 0, 10_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain('3973')
            ->and($statement->refusal())->toContain('1219')
            ->and($statement->unclassifiedAccounts())->toContain(3973);
    });

    it('S1-R2.1 blocks depreciation booked directly against the cost account', function () {
        postCashFlowVoucher($this->fixture, '2026-12-31', 'Extra avskrivning direkt mot anskaffningskontot', [[7831, 1_000_000, 0], [1210, 0, 1_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain('7831')
            ->and($statement->refusal())->toContain('avskrivningar');
    });

    it('S1-R2.1 treats a used overdraft (2330, 2480) as financing, netted under K3 7.12 b, never as likvida medel (7.4 kommentar)', function () {
        postCashFlowVoucher($this->fixture, '2026-11-30', 'Utnyttjad checkräkningskredit', [[1930, 50_000_000, 0], [2480, 0, 50_000_000]]);
        postCashFlowVoucher($this->fixture, '2026-11-30', 'Långfristig checkräkningskredit', [[1930, 20_000_000, 0], [2330, 0, 20_000_000]]);

        $amounts = cashFlow($this->fixture->years[2026])->amounts();

        // Loans stay gross (7.11): the amortisation of 1 200 000 kr is not netted with the overdraft.
        expect($amounts['upptagna_lan'])->toBe(0)
            ->and($amounts['amortering_av_lan'])->toBe(-120_000_000)
            ->and($amounts['forandring_av_checkrakningskredit'])->toBe(70_000_000)
            ->and($amounts['arets_kassaflode'])->toBe(29_240_482 + 70_000_000)
            ->and($amounts['likvida_medel_vid_arets_slut'])->toBe(427_444_482 + 70_000_000);
    });

    it('S1-R2.1 refuses a bank account with a credit balance: an overdraft on 19xx is financing, not cash', function () {
        postCashFlowVoucher($this->fixture, '2026-12-31', 'Överföring till valutakonto', [[1940, 0, 1_000_000], [1930, 1_000_000, 0]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain('1940')
            ->and($statement->refusal())->toContain('checkräkningskredit');
    });

    it('S1-R2.1 puts shareholder contributions and a new issue under financing, with the cash they bring', function () {
        postCashFlowVoucher($this->fixture, '2026-03-01', 'Erhållet aktieägartillskott', [[1930, 20_000_000, 0], [2093, 0, 20_000_000]]);
        postCashFlowVoucher($this->fixture, '2026-10-01', 'Nyemission', [[1930, 30_000_000, 0], [2081, 0, 10_000_000], [2097, 0, 20_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);
        $amounts = $statement->amounts();

        expect($statement->refusal())->toBeNull()
            ->and($amounts['erhallna_aktieagartillskott'])->toBe(20_000_000)
            ->and($amounts['nyemission'])->toBe(30_000_000)
            ->and($amounts['kassaflode_fran_finansieringsverksamheten'])->toBe(-270_000_000 + 50_000_000)
            ->and($amounts['arets_kassaflode'])->toBe(29_240_482 + 50_000_000);
    });

    it('S1-R2.1 refuses when the equity statement cannot be drawn up, with its reason', function () {
        postCashFlowVoucher($this->fixture, '2026-06-30', 'Okänd insättning direkt mot eget kapital', [[1930, 10_000_000, 0], [2091, 0, 10_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain('2091')
            ->and($statement->refusal())->toContain('eget kapital');
    });

    it('S1-R2 refuses no cash flow statement for a större företag, and for a company whose size is undetermined', function () {
        $year = $this->fixture->years[2026];
        setCashFlowPolicy($this->fixture, $year, 'none');
        $accounts = AnnualAccounts::for($year, K3Schedule::class);

        foreach ([K3SizeClass::Storre, K3SizeClass::Undetermined] as $size) {
            try {
                CashFlowStatement::forYear($accounts, K3Policies::forYear($year), $size);
                $this->fail("{$size->value} with none should be refused");
            } catch (CashFlowStatementRefused $refused) {
                expect($refused->reason)->toContain('2 kap. 1 §')
                    ->and($refused->reason)->toContain('kassaflödesanalys');
            }
        }

        setCashFlowPolicy($this->fixture, $year, 'indirect');
        $statement = CashFlowStatement::forYear($accounts, K3Policies::forYear($year), K3SizeClass::Storre);

        expect($statement)->toBeInstanceOf(CashFlowStatement::class)
            ->and($statement?->amounts()['arets_kassaflode'])->toBe(29_240_482);
    });

    it('S1-R2 refuses no cash flow statement for an ekonomisk förening, which may be a bostadsrättsförening (ÅRL 2 kap. 1 § andra stycket)', function () {
        $year = $this->fixture->years[2026];
        setCashFlowPolicy($this->fixture, $year, 'none');
        $accounts = AnnualAccounts::for($year, K3Schedule::class);

        expect(fn () => CashFlowStatement::forYear($accounts, K3Policies::forYear($year), K3SizeClass::Mindre, LegalForm::EkonomiskForening))
            ->toThrow(CashFlowStatementRefused::class, 'bostadsrättsförening');

        $this->fixture->company->forceFill(['legal_form' => LegalForm::EkonomiskForening->value])->save();

        expect(fn () => CashFlowStatement::forYear($accounts, K3Policies::forYear($year), K3SizeClass::Mindre))
            ->toThrow(CashFlowStatementRefused::class, 'bostadsrättsförening');

        $this->fixture->company->forceFill(['legal_form' => LegalForm::Ab->value])->save();

        expect(CashFlowStatement::forYear($accounts, K3Policies::forYear($year), K3SizeClass::Mindre))->toBeNull();
    });

    it('K3 7 kommentar: blocks a car bought with a loan paid straight to the dealer, which is no cash flow', function () {
        postCashFlowVoucher($this->fixture, '2026-05-01', 'Billån', [[1240, 40_000_000, 0], [2350, 0, 40_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain('1240')
            ->and($statement->refusal())->toContain('2350')
            ->and($statement->refusal())->toContain('utan betalning')
            ->and($statement->refusedAccounts())->toContain(1240, 2350);
    });

    it('K3 7 kommentar: blocks a machine bought on supplier credit still unpaid at the year end', function () {
        postCashFlowVoucher($this->fixture, '2026-12-20', 'Maskin på kredit', [[1210, 10_000_000, 0], [2440, 0, 10_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain('1210')
            ->and($statement->refusal())->toContain('2440');
    });

    it('K3 7.6: a machine bought on supplier credit and paid in the year is an investing outflow', function () {
        postCashFlowVoucher($this->fixture, '2026-10-01', 'Maskin på kredit', [[1210, 10_000_000, 0], [2640, 2_500_000, 0], [2440, 0, 12_500_000]]);
        postCashFlowVoucher($this->fixture, '2026-10-30', 'Betalning maskin', [[2440, 12_500_000, 0], [1930, 0, 12_500_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toBeNull()
            ->and($statement->amounts()['forvarv_av_materiella_anlaggningstillgangar'])->toBe(-240_000_000 - 10_000_000)
            ->and($statement->amounts()['arets_kassaflode'])->toBe(29_240_482 - 12_500_000);
    });

    it('K3 7 kommentar: blocks an owner loan converted to a shareholder contribution (konvertering av skuld till eget kapital)', function () {
        postCashFlowVoucher($this->fixture, '2026-02-01', 'Lån från ägare', [[1930, 5_000_000, 0], [2393, 0, 5_000_000]]);
        postCashFlowVoucher($this->fixture, '2026-06-01', 'Lån omvandlat till tillskott', [[2393, 5_000_000, 0], [2093, 0, 5_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain('2393')
            ->and($statement->refusal())->toContain('2093');
    });

    it('K3 7 kommentar: blocks a dividend set off against a receivable from the owner', function () {
        postCashFlowVoucher($this->fixture, '2026-11-30', 'Beslut extra utdelning', [[2091, 1_000_000, 0], [2898, 0, 1_000_000]]);
        postCashFlowVoucher($this->fixture, '2026-12-01', 'Utdelning kvittad', [[2898, 1_000_000, 0], [1385, 0, 1_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain('2898')
            ->and($statement->refusal())->toContain('1385');
    });

    it('S1-R2.1 blocks a movement on receivables from group companies, owners and related parties (1660–1689)', function () {
        postCashFlowVoucher($this->fixture, '2026-02-01', 'Lån till ägare', [[1685, 1_000_000, 0], [1930, 0, 1_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->unclassifiedAccounts())->toBe([1685])
            ->and($statement->refusal())->toContain('1685');
    });

    it('K3 7.11: shows borrowing and repayment gross, never netted', function () {
        postCashFlowVoucher($this->fixture, '2026-02-01', 'Nytt banklån', [[1930, 100_000_000, 0], [2350, 0, 100_000_000]]);
        postCashFlowVoucher($this->fixture, '2026-11-01', 'Lösen av lån', [[2350, 100_000_000, 0], [1930, 0, 100_000_000]]);

        $amounts = cashFlow($this->fixture->years[2026])->amounts();

        // The reclassification of next year's amortisation (2350 to 2410) moves no cash and adds nothing.
        expect($amounts['upptagna_lan'])->toBe(100_000_000)
            ->and($amounts['amortering_av_lan'])->toBe(-120_000_000 - 100_000_000)
            ->and($amounts['arets_kassaflode'])->toBe(29_240_482);
    });

    it('K3 7.11: a correction is netted with the voucher it corrects, so it does not inflate the gross figures', function () {
        $original = postCashFlowVoucher($this->fixture, '2026-02-01', 'Nytt banklån', [[1930, 100_000_000, 0], [2350, 0, 100_000_000]]);
        app(CorrectVoucher::class)($this->fixture->owner, $original, 'Fel belopp', '2026-02-02', [
            ['account' => 1930, 'debit_ore' => 80_000_000, 'credit_ore' => 0],
            ['account' => 2350, 'debit_ore' => 0, 'credit_ore' => 80_000_000],
        ]);
        $reversed = postCashFlowVoucher($this->fixture, '2026-03-01', 'Lån i fel företag', [[1930, 50_000_000, 0], [2350, 0, 50_000_000]]);
        app(CorrectVoucher::class)($this->fixture->owner, $reversed, 'Fel företag', '2026-03-02');

        $amounts = cashFlow($this->fixture->years[2026])->amounts();

        expect($amounts['upptagna_lan'])->toBe(80_000_000)
            ->and($amounts['amortering_av_lan'])->toBe(-120_000_000);
    });

    it('S1-R2.1 treats an advance for a machine (BAS 1288) as an investing outflow, not as accumulated depreciation', function () {
        postCashFlowVoucher($this->fixture, '2026-10-01', 'Förskott maskin', [[1288, 2_000_000, 0], [1930, 0, 2_000_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toBeNull()
            ->and($statement->amounts()['forvarv_av_materiella_anlaggningstillgangar'])->toBe(-240_000_000 - 2_000_000);
    });

    it('K3 7.13: blocks currency differences until their effect on likvida medel can be shown separately', function (int $account, int $counter) {
        postCashFlowVoucher($this->fixture, '2026-03-01', 'Till valutakonto', [[1940, 10_000_000, 0], [1930, 0, 10_000_000]]);
        postCashFlowVoucher($this->fixture, '2026-12-31', 'Omräkning', [[$counter, 300_000, 0], [$account, 0, 300_000]]);

        $statement = cashFlow($this->fixture->years[2026]);

        expect($statement->refusal())->toContain((string) $account)
            ->and($statement->refusal())->toContain('valutakurs');
    })->with([
        'gain on a EUR bank account' => [8330, 1940],
        'operating gain' => [3960, 1510],
    ]);

    it('K3 7.13, 7.9 a: blocks an unrealised currency loss on a loan', function () {
        postCashFlowVoucher($this->fixture, '2026-12-31', 'Omräkning EUR-lån', [[8430, 500_000, 0], [2350, 0, 500_000]]);

        expect(cashFlow($this->fixture->years[2026])->refusal())->toContain('8430');
    });

    it('K3 7.14: shows dividends received separately, in operating activities (7.15)', function () {
        postCashFlowVoucher($this->fixture, '2026-05-01', 'Utdelning aktier', [[1930, 700_000, 0], [8210, 0, 700_000]]);

        $statement = cashFlow($this->fixture->years[2026]);
        $rows = collect($statement->rows())->keyBy('key');

        expect($statement->refusal())->toBeNull()
            ->and($rows['tillaggsupplysning_erhallen_utdelning'])->toMatchArray(['amount' => 700_000, 'point' => '7.14'])
            ->and($statement->amounts()['resultat_efter_finansiella_poster'])->toBe(541_181_000 + 700_000);
    });

    it('S1-R2.1 refuses the direct method, which NONOX does not build, and a missing policy', function () {
        $year = $this->fixture->years[2026];
        $accounts = AnnualAccounts::for($year, K3Schedule::class);

        expect(fn () => CashFlowStatement::forYear($accounts, K3Policies::forYear($year), K3SizeClass::Mindre))
            ->toThrow(CashFlowStatementRefused::class, 'Ingen princip');

        // A value the policy screen cannot store, as an import or an older version could leave it.
        CompanyAccountingPolicy::query()->create(['key' => K3PolicyKey::CashFlowStatement, 'value' => 'direct', 'applies_from' => $year->start_date->toDateString(), 'set_by' => $this->fixture->owner->id]);

        expect(fn () => CashFlowStatement::forYear($accounts, K3Policies::forYear($year), K3SizeClass::Mindre))
            ->toThrow(CashFlowStatementRefused::class, 'direkt metod');
    });

    it('S1-R2.1 prints F-S1-a in whole kronor: every line rounded, every sum the sum of the printed lines, likvida medel as the balance sheet prints them', function () {
        foreach ([2025, 2026] as $year) {
            $accounts = AnnualAccounts::for($this->fixture->years[$year], K3Schedule::class);
            $printed = collect(CashFlowPrintedFigures::apply(CashFlowStatement::of($accounts)->rows()))->keyBy('key');
            $balance = PrintedFigures::apply([
                'income' => $accounts->incomeStatement(), 'hasPreviousIncome' => $accounts->previousYear !== null, 'balance' => $accounts->balanceSheet(),
                'multiYear' => [], 'netSalesVariations' => [], 'equityChanges' => $accounts->equityChanges(),
                'disposition' => ['dividend' => 0], 'fixedAssets' => $accounts->fixedAssetNotes(), 'pledged' => [], 'contingent' => null,
            ], K3Schedule::class)['balance'];
            $cash = collect($balance['sections'])->firstWhere('key', 'cash')['lines'][0];

            foreach ($printed as $row) {
                expect($row['amount'] % 100)->toBe(0, "{$year} {$row['key']}");
            }

            $sum = fn (array $keys): int => array_sum(array_map(fn (string $key): int => $printed[$key]['amount'] ?? 0, $keys));

            expect($printed['kassaflode_fran_den_lopande_verksamheten']['amount'])->toBe($sum(['kassaflode_fore_forandring_av_rorelsekapital', 'forandring_varulager', 'forandring_rorelsefordringar', 'forandring_rorelseskulder']))
                ->and($printed['arets_kassaflode']['amount'])->toBe($sum(['kassaflode_fran_den_lopande_verksamheten', 'kassaflode_fran_investeringsverksamheten', 'kassaflode_fran_finansieringsverksamheten']))
                ->and($printed['likvida_medel_vid_arets_slut']['amount'] - $printed['likvida_medel_vid_arets_borjan']['amount'])->toBe($printed['arets_kassaflode']['amount'])
                ->and($printed['likvida_medel_vid_arets_slut']['amount'])->toBe($cash['amount']);
        }

        // 2026: the tax paid −789 825,18 kr prints as −789 825 kr; the year's cash flow 292 404,82 kr as 292 405 kr.
        $printed = collect(CashFlowPrintedFigures::apply(cashFlow($this->fixture->years[2026])->rows()))->keyBy('key');

        expect($printed['betald_inkomstskatt']['amount'])->toBe(-78_982_500)
            ->and($printed['arets_kassaflode']['amount'])->toBe(29_240_500)
            ->and($printed['arets_kassaflode']['previous'])->toBe(98_204_000);
    });

    it('S1-R2.1 reads the ledger with a fixed number of queries, however many vouchers there are, also after ANALYZE', function () {
        $count = function (): int {
            $accounts = AnnualAccounts::for($this->fixture->years[2026], K3Schedule::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            CashFlowStatement::of($accounts)->rows();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $short = $count();

        foreach (range(1, 8) as $month) {
            postCashFlowVoucher($this->fixture, sprintf('2026-%02d-20', $month), 'Aktieägartillskott', [[1930, 100_000, 0], [2093, 0, 100_000]]);
            postCashFlowVoucher($this->fixture, sprintf('2026-%02d-21', $month), 'Inköp av maskin', [[1210, 100_000, 0], [1930, 0, 100_000]]);
        }

        // Per year one query for the equity statement and one for the vouchers that touch
        // investing and financing accounts; for the previous year beside it also AnnualAccounts
        // (the year's lookup, accounts, opening balances, movements, goods-for-resale accounts).
        expect($short)->toBe(9)
            ->and($count())->toBe($short);

        // With fresh planner statistics the queries still finish at once (S1-P7 review:
        // an IN subquery planned as a nested loop semi join hung after ANALYZE).
        DB::statement('ANALYZE vouchers, voucher_rows, accounts');
        $started = microtime(true);
        expect($count())->toBe($short)
            ->and(microtime(true) - $started)->toBeLessThan(10.0);
    });

    it('S1-R2.1 refuses when likvida medel at the start of the year differ from last year\'s closing balance', function () {
        $year = $this->fixture->years[2026];

        foreach ([[1930, 10_000], [2091, -10_000]] as [$number, $delta]) {
            $id = Account::query()->where('number', $number)->value('id');
            $balance = OpeningBalance::query()->where('fiscal_year_id', $year->id)->where('account_id', $id)->firstOrFail();
            $balance->forceFill(['amount_ore' => $balance->amount_ore->ore + $delta])->save();
        }

        expect(cashFlow($year)->refusal())->toContain('1930');
    });

    it('S1-R2.1 needs the K3 layout; the K2 report has no cash flow statement', function () {
        expect(fn () => CashFlowStatement::of(AnnualAccounts::for($this->fixture->years[2026], K2Schedule::class)))->toThrow(LogicException::class);
    });
});

describe('F-S1-c', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
        $this->fixture = S1Fixture::load('f-s1-c');
        CurrentCompany::set($this->fixture->company);
        $this->actingAs($this->fixture->owner);

        $company = json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-c/company.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($company['prior_year_size_figures'] as $figures) {
            app(SavePriorYearSizeFigures::class)($this->fixture->owner->fresh(), $this->fixture->company, $figures);
        }

        $this->year = $this->fixture->years[2026];
        setCashFlowPolicy($this->fixture, $this->year, $company['accounting_policies']['cash_flow_statement']);
    });

    it('S1-R2 gives a mindre företag with the policy none no cash flow statement, and refuses nothing', function () {
        $size = K3CompanySize::forFiscalYear($this->year);
        $accounts = AnnualAccounts::for($this->year, K3Schedule::class);

        expect($size->class)->toBe(K3SizeClass::Mindre)
            ->and($this->fixture->expected['cash_flow_statement']['2026'])->toBeNull()
            ->and(CashFlowStatement::forYear($accounts, K3Policies::forYear($this->year), $size->class))->toBeNull()
            ->and(K3Policies::forYear($this->year)->refusals($size->class === K3SizeClass::Storre))->toBe([])
            ->and($accounts->yearResult())->toBe($this->fixture->expected['year_result']['2026']);
    });

    it('S1-R2, K3 7.1: a voluntary statement of a mindre företag follows chapter 7 in full, to the öre', function () {
        setCashFlowPolicy($this->fixture, $this->year, 'indirect');
        $statement = CashFlowStatement::forYear(AnnualAccounts::for($this->year, K3Schedule::class), K3Policies::forYear($this->year), K3CompanySize::forFiscalYear($this->year)->class);
        $expected = expectedCashFlow($this->fixture->expected['voluntary_cash_flow_statement']['2026']);

        expect($statement)->toBeInstanceOf(CashFlowStatement::class)
            ->and($statement?->refusal())->toBeNull();

        foreach ($expected as $key => $amount) {
            expect($statement?->amounts()[$key])->toBe($amount, $key);
        }

        foreach (array_diff_key((array) $statement?->amounts(), $expected) as $key => $amount) {
            expect($amount)->toBe(0, $key);
        }
    });
});
