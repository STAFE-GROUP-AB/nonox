<?php

declare(strict_types=1);

use App\Actions\Group\CreatePairedDraft;
use App\Actions\Group\SetPairReference;
use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanyMirror;
use App\Livewire\Group\PairedDraftPanel;
use App\Models\AuditLog;
use App\Models\CompanyHolding;
use App\Models\FiscalYear;
use App\Models\IntercompanyPair;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\E27Group;

/*
 * Paired drafts for intra-group transactions without an invoice (ADR-081
 * gap 1, E27; owner decision 4). Parent A lends 50 000 kr to subsidiary B.
 * From A's posted voucher, Olle (owner of A, administrator of B) makes a
 * DRAFT in B with the mirrored rows; B's people post it. Nothing is posted
 * in B, A's voucher is untouched, and each company keeps its own pair row.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    $this->set = E27Group::make(receipt: false);
    $this->set->account($this->set->a, 1660, 'Kortfristiga fordringar hos koncernföretag');
    $this->set->account($this->set->b, 2860, 'Kortfristiga skulder till koncernföretag');
    $this->loan = CurrentCompany::run($this->set->a, fn (): Voucher => Bookkeeping::postRows($this->set->olle, $this->set->a, '2026-03-05', [1660 => 50_000_00, 1930 => -50_000_00]));
    CurrentCompany::set($this->set->a);
    $this->set->olle->switchCompany($this->set->a);
    $this->actingAs($this->set->olle->fresh());
});

/** @return array<string, mixed> the proposal as input, with A's kind */
function e27PairInput(object $test, string $kind = 'RECEIVABLE'): array
{
    return [...app(CreatePairedDraft::class)->propose($test->set->olle, $test->loan), 'kind' => $kind];
}

it('proposes the mirrored rows: debit and credit swapped, a group receivable becoming a group debt', function () {
    $proposal = app(CreatePairedDraft::class)->propose($this->set->olle, $this->loan);

    expect($proposal['kind'])->toBe(IntercompanyKind::Receivable)
        ->and($proposal['voucher_date'])->toBe('2026-03-05')
        ->and(array_map(fn (array $row): array => [$row['account'], $row['debit_ore'], $row['credit_ore']], $proposal['rows']))
        ->toEqualCanonicalizing([['2860', 0, 50_000_00], ['1930', 50_000_00, 0]])
        ->and(IntercompanyMirror::account(1321))->toBe(2361)
        ->and(IntercompanyMirror::account(8830))->toBe(8820)
        ->and(IntercompanyMirror::account(3001))->toBe(3001)
        ->and(IntercompanyMirror::kind(IntercompanyKind::GroupContributionGiven))->toBe(IntercompanyKind::GroupContributionReceived);
});

it('creates a draft in the group company, never posted, with a pair row on each side', function () {
    $before = $this->loan->fresh()->only(['status', 'number', 'hash']);

    $pair = app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this));

    $draft = CurrentCompany::run($this->set->b, fn (): Voucher => Voucher::query()->where('status', 'DRAFT')->with('rows.account')->sole());
    $theirs = CurrentCompany::run($this->set->b, fn (): IntercompanyPair => IntercompanyPair::query()->sole());

    expect($draft->number)->toBeNull()
        ->and($draft->created_by)->toBe($this->set->olle->id)
        ->and($draft->rows->map(fn (VoucherRow $row): array => [$row->account->number, $row->debit_ore->ore, $row->credit_ore->ore])->all())
        ->toEqualCanonicalizing([[2860, 0, 50_000_00], [1930, 50_000_00, 0]])
        ->and($pair->company_id)->toBe($this->set->a->id)
        ->and($pair->voucher_id)->toBe($this->loan->id)
        ->and($pair->counterparty_org_number)->toBe($this->set->b->org_number)
        ->and($pair->counterparty_voucher_ref)->toBeNull()
        ->and($pair->kind)->toBe(IntercompanyKind::Receivable)
        ->and($theirs->voucher_id)->toBe($draft->id)
        ->and($theirs->counterparty_org_number)->toBe($this->set->a->org_number)
        ->and($theirs->counterparty_voucher_ref)->toBe(CreatePairedDraft::reference($this->loan))
        ->and($theirs->kind)->toBe(IntercompanyKind::Payable)
        ->and($this->loan->fresh()->only(['status', 'number', 'hash']))->toEqual($before);

    $this->artisan('ledger:verify', ['company' => $this->set->a->org_number])->assertSuccessful();
    $this->artisan('ledger:verify', ['company' => $this->set->b->org_number])->assertSuccessful();

    $aLog = AuditLog::query()->where('company_id', $this->set->a->id)->where('action', 'intercompany.pair_created')->sole();
    $bLog = AuditLog::query()->where('company_id', $this->set->b->id)->where('action', 'intercompany.paired_draft_created')->sole();

    expect(array_keys($aLog->payload))->toEqualCanonicalizing(['pair_id', 'voucher_id', 'kind', 'rows'])
        ->and(array_keys($bLog->payload))->toEqualCanonicalizing(['pair_id', 'kind', 'rows']);
});

it('refuses a row whose account the group company lacks, and writes nothing', function () {
    $input = e27PairInput($this);
    $input['rows'][0]['account'] = '2369';

    expect(fn () => app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, $input))
        ->toThrow(ValidationException::class, 'kontot finns inte i kontoplanen')
        ->and(CurrentCompany::run($this->set->b, fn (): int => Voucher::query()->where('status', 'DRAFT')->count()))->toBe(0)
        ->and(IntercompanyPair::query()->count())->toBe(0);
});

it('refuses a cost centre the group company does not have', function () {
    $input = e27PairInput($this);
    $input['rows'][0]['cost_center'] = 'FINNSINTE';

    expect(fn () => app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, $input))
        ->toThrow(ValidationException::class, 'kostnadsstället FINNSINTE finns inte');
});

it('makes one draft per voucher and counterpart while it exists, and another once the receiver deleted it', function () {
    app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this));

    expect(fn () => app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this)))
        ->toThrow(ValidationException::class, 'Det finns redan ett motsvarande utkast');

    CurrentCompany::run($this->set->b, fn () => Voucher::query()->where('status', 'DRAFT')->sole()->delete());
    app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this));

    expect(IntercompanyPair::query()->count())->toBe(1)
        ->and(CurrentCompany::run($this->set->b, fn (): int => IntercompanyPair::query()->count()))->toBe(1);
});

it('refuses a draft from a draft, without vouchers:create in the sender, outside the group and after the membership ended', function () {
    $draft = CurrentCompany::run($this->set->a, fn (): Voucher => Bookkeeping::draft($this->set->olle, $this->set->a));
    $auditor = Bookkeeping::member($this->set->a, 'auditor');

    expect(fn () => app(CreatePairedDraft::class)->propose($this->set->olle, $draft))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CreatePairedDraft::class)($auditor, $this->loan, $this->set->b->id, e27PairInput($this)))->toThrow(AuthorizationException::class);

    CurrentCompany::run($this->set->a, fn () => CompanyHolding::query()->whereKey($this->set->holding->id)->update(['disposed_on' => '2026-03-10']));

    expect(CreatePairedDraft::counterparts($this->set->olle->fresh(), $this->set->a))->toBeEmpty()
        ->and(fn () => app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this)))->toThrow(ValidationException::class, 'Välj ett koncernföretag');
});

it('refuses a kind that is not an intra-group item without an invoice', function () {
    expect(fn () => app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this, 'SALES')))
        ->toThrow(ValidationException::class, 'Välj vad det är för mellanhavande');
});

it('enters the counterpart\'s voucher reference on the company\'s own pair row', function () {
    $pair = app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this));

    app(SetPairReference::class)($this->set->olle, $this->set->a, $pair->id, ' A  7 ');

    expect($pair->fresh()->counterparty_voucher_ref)->toBe('A 7')
        ->and(fn () => app(SetPairReference::class)($this->set->olle, $this->set->a, $pair->id, str_repeat('A', 41)))->toThrow(ValidationException::class);
});

it('takes the amounts from the posted voucher, never from the form, on the voucher page', function () {
    Livewire::test(PairedDraftPanel::class, ['voucherId' => $this->loan->id])
        ->assertSee(__('Skapa motsvarande utkast'))
        ->call('start')
        ->assertSet('counterpartId', $this->set->b->id)
        ->assertSet('kind', 'RECEIVABLE')
        ->set('rows.0.debit_ore', 1)
        ->set('rows.1.credit_ore', 1)
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSee(__('motpart :number', ['number' => $this->set->b->org_number]));

    $draft = CurrentCompany::run($this->set->b, fn (): Voucher => Voucher::query()->where('status', 'DRAFT')->with('rows')->sole());

    expect($draft->rows->sum(fn (VoucherRow $row): int => $row->debit_ore->ore))->toBe(50_000_00)
        ->and($draft->rows->sum(fn (VoucherRow $row): int => $row->credit_ore->ore))->toBe(50_000_00);

    $this->get(route('accounting.vouchers.show', $this->loan))->assertOk();
});

it('shows the pair on the receiver\'s draft, where its people post it', function () {
    app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this));
    $draft = CurrentCompany::run($this->set->b, fn (): Voucher => Voucher::query()->where('status', 'DRAFT')->sole());

    CurrentCompany::set($this->set->b);
    $this->set->bea->switchCompany($this->set->b);
    $this->actingAs($this->set->bea->fresh());

    Livewire::test(PairedDraftPanel::class, ['voucherId' => $draft->id])
        ->assertSee(__('motpartens verifikation :reference', ['reference' => CreatePairedDraft::reference($this->loan)]))
        ->assertDontSee(__('Skapa motsvarande utkast'));
});

it('opens the voucher page in a fixed number of queries in an active group, however many pairs the company has', function () {
    app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this));

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('accounting.vouchers.show', $this->loan))->assertOk()->assertSee(__('Koncernföretag'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $short = $count();

    foreach (range(1, 5) as $i) {
        $other = CurrentCompany::run($this->set->a, fn (): Voucher => Bookkeeping::postRows($this->set->olle, $this->set->a, '2026-03-06', [1660 => 1_000_00 * $i, 1930 => -1_000_00 * $i]));
        app(CreatePairedDraft::class)($this->set->olle, $other, $this->set->b->id, [...app(CreatePairedDraft::class)->propose($this->set->olle, $other), 'kind' => 'RECEIVABLE']);
    }

    expect($count())->toBe($short);
});

it('ignores amounts and extra rows sent with the form: the draft has the posted voucher\'s', function () {
    $input = e27PairInput($this);
    $input['rows'][0]['debit_ore'] = 1;
    $input['rows'][0]['credit_ore'] = 999_999_00;
    $input['rows'][] = ['account' => '1930', 'debit_ore' => 5_000_00, 'credit_ore' => 0];

    app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, $input);

    $draft = CurrentCompany::run($this->set->b, fn (): Voucher => Voucher::query()->where('status', 'DRAFT')->with('rows.account')->sole());

    expect($draft->rows->map(fn (VoucherRow $row): array => [$row->account->number, $row->debit_ore->ore, $row->credit_ore->ore])->all())
        ->toEqualCanonicalizing([[2860, 0, 50_000_00], [1930, 50_000_00, 0]]);
});

it('names the source voucher with its fiscal year, so the same number another year is not taken for a duplicate', function () {
    $reference = CreatePairedDraft::reference($this->loan);

    // A pair in B for the voucher with the same series and number in an earlier year.
    $earlier = CurrentCompany::run($this->set->b, fn (): Voucher => Bookkeeping::draft($this->set->bea, $this->set->b));
    CurrentCompany::run($this->set->b, fn () => IntercompanyPair::create([
        'voucher_id' => $earlier->id, 'counterparty_org_number' => $this->set->a->org_number,
        'counterparty_voucher_ref' => $this->loan->reference().' (2025)', 'kind' => 'PAYABLE', 'created_by' => $this->set->bea->id,
    ]));

    app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this));

    expect($reference)->toBe($this->loan->reference().' (2026)')
        ->and(CurrentCompany::run($this->set->b, fn (): int => IntercompanyPair::query()->where('counterparty_voucher_ref', $reference)->count()))->toBe(1)
        ->and(fn () => app(CreatePairedDraft::class)($this->set->olle, $this->loan, $this->set->b->id, e27PairInput($this)))->toThrow(ValidationException::class, 'Det finns redan ett motsvarande utkast');
});

it('warns on interest and dividend accounts, which keep their number', function () {
    $interest = CurrentCompany::run($this->set->a, fn (): Voucher => Bookkeeping::postRows($this->set->olle, $this->set->a, '2026-03-07', [1930 => 500_00, 8310 => -500_00]));
    $rows = app(CreatePairedDraft::class)->propose($this->set->olle, $interest)['rows'];

    expect(collect($rows)->firstWhere('account', '8310')['check'])->toBeTrue()
        ->and(collect($rows)->firstWhere('account', '1930')['check'])->toBeFalse();
});

it('labels two shortened fiscal years in one calendar year differently', function () {
    $first = (clone $this->loan)->setRelation('fiscalYear', new FiscalYear(['start_date' => '2026-01-01', 'end_date' => '2026-06-30']));
    $second = (clone $this->loan)->setRelation('fiscalYear', new FiscalYear(['start_date' => '2026-07-01', 'end_date' => '2026-12-31']));

    expect(CreatePairedDraft::reference($first))->toBe($this->loan->reference().' (20260101–20260630)')
        ->and(CreatePairedDraft::reference($second))->toBe($this->loan->reference().' (20260701–20261231)');
});
