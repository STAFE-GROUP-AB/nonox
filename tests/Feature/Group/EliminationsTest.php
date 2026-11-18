<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\GrantGroupRole;
use App\Actions\Group\RecordGroupEntry;
use App\Actions\Group\RecordHolding;
use App\Domain\Accounting\AccountType;
use App\Domain\Group\ConsolidationView;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\EliminationSuggestions;
use App\Domain\Group\EliminationType;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAccessLevel;
use App\Domain\Group\GroupEntryKind;
use App\Domain\Group\GroupLedger;
use App\Livewire\Group\EliminationsPage;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroupRun;
use App\Models\FiscalYear;
use App\Models\GroupEntry;
use App\Models\GroupEntryRow;
use App\Models\IntercompanyRowTag;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Eliminations and the group ledger (ADR-060, addendum 2026-11-07, 3.2,
 * S2-1.5; ADR-081 E25). A parent P owns A and B. Olle owns P and is
 * administrator in A and B. A sells to B on credit and P lends to A; the
 * rows are tagged in each company. Suggestions match the tags across the
 * companies; accepted ones become numbered, chained, append-only entries in
 * the group ledger, applied to runs of exactly their period, and nothing is
 * ever written into a company's books.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
    $this->olle = User::factory()->withCompany()->create(['name' => 'Olle']);
    $this->p = $this->olle->currentCompany();
    $this->p->forceFill(['name' => 'Moder Exempel AB'])->save();
    app(InitialiseBookkeeping::class)($this->p, $this->olle, CarbonImmutable::parse('2026-01-15'));

    foreach (['a' => 'Dotter A Exempel AB', 'b' => 'Dotter B Exempel AB'] as $key => $name) {
        $owner = User::factory()->withCompany()->create();
        $company = $owner->currentCompany();
        $company->forceFill(['name' => $name])->save();
        app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse('2026-01-15'));
        $company->users()->attach($this->olle, ['role' => 'admin']);
        $this->{$key} = $company;
    }

    foreach ([$this->p, $this->a, $this->b] as $company) {
        e25Accounts($company, [1310, 1320, 1510, 2081, 2360, 2440, 3011, 4010]);
    }

    $this->olle = $this->olle->fresh();
    $this->holdingA = e25Holding($this->olle, $this->p, $this->a);
    $this->holdingB = e25Holding($this->olle, $this->p, $this->b);
    $this->group = app(CreateCorporateGroup::class)($this->olle->fresh(), $this->p, 'Exempelkoncernen');
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a);
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->b);

    // A sells to B for 1 000 kr on credit; P lends A 500 kr; P sells externally for 300 kr.
    $sale = e25Post($this->olle, $this->a, '2026-04-01', [1510 => 100_000, 3011 => -100_000]);
    e25Tag($this->a, $sale, 1510, $this->b, 'RECEIVABLE');
    e25Tag($this->a, $sale, 3011, $this->b, 'SALES');
    $purchase = e25Post($this->olle, $this->b, '2026-04-01', [4010 => 100_000, 2440 => -100_000]);
    e25Tag($this->b, $purchase, 4010, $this->a, 'PURCHASES');
    e25Tag($this->b, $purchase, 2440, $this->a, 'PAYABLE');
    $loan = e25Post($this->olle, $this->p, '2026-05-01', [1320 => 50_000, 1930 => -50_000]);
    e25Tag($this->p, $loan, 1320, $this->a, 'LOAN_RECEIVABLE');
    $borrowed = e25Post($this->olle, $this->a, '2026-05-01', [1930 => 50_000, 2360 => -50_000]);
    e25Tag($this->a, $borrowed, 2360, $this->p, 'LOAN_PAYABLE');
    e25Post($this->olle, $this->p, '2026-06-01', [1930 => 30_000, 3011 => -30_000]);
});

function e25Holding(User $user, Company $holder, Company $held, string $capital = '100'): CompanyHolding
{
    return app(RecordHolding::class)($user->fresh(), $holder, [
        'held_org_number' => $held->org_number,
        'held_name' => $held->name,
        'capital' => $capital,
        'voting' => '100',
        'control_basis' => ControlBasis::Votes->value,
        'acquired_on' => '2024-01-01',
    ]);
}

/** @param  list<int>  $numbers */
function e25Accounts(Company $company, array $numbers): void
{
    CurrentCompany::run($company, function () use ($numbers): void {
        foreach ($numbers as $number) {
            if (! Account::query()->where('number', $number)->exists()) {
                Account::create(['number' => $number, 'name' => "Konto {$number}", 'type' => AccountType::forNumber($number), 'is_active' => true]);
            }
        }
    });
}

/** @param  array<int, int>  $rows  account => signed öre */
function e25Post(User $user, Company $company, string $date, array $rows): Voucher
{
    return CurrentCompany::run($company, fn () => Bookkeeping::postRows($user->fresh(), $company, $date, $rows));
}

function e25Tag(Company $company, Voucher $voucher, int $account, Company $counterparty, string $kind): void
{
    CurrentCompany::run($company, function () use ($voucher, $account, $counterparty, $kind): void {
        $row = $voucher->rows()->whereHas('account', fn ($query) => $query->where('number', $account))->firstOrFail();
        IntercompanyRowTag::create(['voucher_row_id' => $row->id, 'counterparty_org_number' => $counterparty->org_number, 'kind' => $kind, 'source' => 'MANUAL']);
    });
}

/** @return list<array<string, mixed>> */
function e25Suggestions(object $test, ?User $user = null, string $from = '2026-01-01', string $to = '2026-12-31'): array
{
    $user = ($user ?? $test->olle)->fresh();

    return app(EliminationSuggestions::class)->compute(GroupAccess::for($user), $test->group->fresh(), CarbonImmutable::parse($from), CarbonImmutable::parse($to))['suggestions'];
}

/** @return array<string, mixed> */
function e25Suggestion(object $test, string $key): array
{
    return collect(e25Suggestions($test))->firstWhere('key', $key) ?? throw new LogicException("No suggestion {$key}.");
}

function e25Accept(object $test, string $key, ?User $user = null, string $from = '2026-01-01', string $to = '2026-12-31'): GroupEntry
{
    return app(RecordGroupEntry::class)->accept(($user ?? $test->olle)->fresh(), $test->group->id, [
        'from' => $from, 'to' => $to, 'key' => $key, 'fingerprint' => e25Suggestion($test, $key)['fingerprint'],
    ]);
}

function e25Run(object $test, ?User $user = null, string $from = '2026-01-01', string $to = '2026-12-31'): CorporateGroupRun
{
    return app(ConsolidateGroup::class)(($user ?? $test->olle)->fresh(), $test->group->id, ['from' => $from, 'to' => $to]);
}

/** @return array<int, int> account => amount as presented, over both statements */
function e25Figures(CorporateGroupRun $run): array
{
    $amounts = [];

    foreach ([$run->output['income']['groups'], $run->output['balance']['assets']['groups'], $run->output['balance']['equity']['groups']] as $groups) {
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $amounts[$row['number']] = $row['amount'];
            }
        }
    }

    ksort($amounts);

    return $amounts;
}

/** Adds to an account's opening balance in a year (one row per account and year). */
function e25AddOpening(FiscalYear $year, int $accountId, int $amount): void
{
    $row = OpeningBalance::query()->firstOrNew(['fiscal_year_id' => $year->id, 'account_id' => $accountId]);
    $row->amount_ore = ($row->exists ? (int) $row->getRawOriginal('amount_ore') : 0) + $amount;
    $row->save();
}

/** Runs a statement that must fail inside a savepoint, rolled back afterwards, with the group ledger's checks deferred again. */
function e25Savepoint(callable $attempt): void
{
    DB::beginTransaction();

    try {
        DB::statement('SET CONSTRAINTS group_entries_balanced, group_entry_rows_balanced DEFERRED');
        $attempt();
    } finally {
        DB::rollBack();
    }
}

it('matches tagged rows across the companies into suggestions with both sides', function () {
    $suggestions = collect(e25Suggestions($this))->keyBy('key');
    $sales = $suggestions["SALES_PURCHASES:{$this->a->id}:{$this->b->id}"];
    $debts = $suggestions["RECEIVABLE_PAYABLE:{$this->b->id}:{$this->a->id}"];
    $loan = $suggestions["LOAN:{$this->a->id}:{$this->p->id}"];

    expect($suggestions)->toHaveCount(3)
        ->and($sales)->toMatchArray(['type' => EliminationType::SalesPurchases, 'status' => EliminationSuggestions::MATCHED, 'difference' => 0, 'total_ore' => 100_000, 'accepted_entry' => null])
        ->and($sales['credit'])->toBe(['accounts' => [3011 => 100_000], 'total' => 100_000])
        ->and($sales['debit'])->toBe(['accounts' => [4010 => 100_000], 'total' => 100_000])
        ->and($sales['rows'])->toBe([
            ['company_id' => $this->a->id, 'account_number' => 3011, 'group_account_number' => 3011, 'debit_ore' => 100_000, 'credit_ore' => 0],
            ['company_id' => $this->b->id, 'account_number' => 4010, 'group_account_number' => 4010, 'debit_ore' => 0, 'credit_ore' => 100_000],
        ])
        ->and($debts['credit']['accounts'])->toBe([2440 => 100_000])
        ->and($debts['debit']['accounts'])->toBe([1510 => 100_000])
        ->and($loan['credit']['accounts'])->toBe([2360 => 50_000])
        ->and($loan['debit']['accounts'])->toBe([1320 => 50_000])
        ->and($loan['status'])->toBe(EliminationSuggestions::MATCHED);
});

it('shows a mismatch with both sides and the difference, and refuses to accept it', function () {
    // B's second purchase from A is tagged; A's matching sale is not.
    $more = e25Post($this->olle, $this->b, '2026-09-01', [4010 => 20_000, 2440 => -20_000]);
    e25Tag($this->b, $more, 4010, $this->a, 'PURCHASES');

    $sales = e25Suggestion($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");

    expect($sales)->toMatchArray(['status' => EliminationSuggestions::MISMATCH, 'difference' => -20_000, 'rows' => [], 'total_ore' => 0])
        ->and($sales['credit']['total'])->toBe(100_000)
        ->and($sales['debit']['total'])->toBe(120_000)
        ->and(fn () => e25Accept($this, $sales['key']))->toThrow(ValidationException::class, 'Förslaget har en differens')
        ->and(GroupEntry::query()->count())->toBe(0);
});

it('accepts a suggestion into the group ledger, numbered, chained and logged in each company it names', function () {
    $first = e25Accept($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");
    $second = e25Accept($this, "LOAN:{$this->a->id}:{$this->p->id}");

    expect($first)->toMatchArray(['number' => 1, 'previous_hash' => null, 'rows_count' => 2, 'total_ore' => 100_000, 'suggestion_key' => "SALES_PURCHASES:{$this->a->id}:{$this->b->id}"])
        ->and($first->kind)->toBe(GroupEntryKind::Elimination)
        ->and($first->entry_date->toDateString())->toBe('2026-12-31')
        ->and($second->number)->toBe(2)
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($first->fresh()->recomputedHash())->toBe($first->hash)
        ->and(GroupLedger::verify($this->group))->toBe(['entries' => 2, 'intact' => true, 'broken' => []]);

    $logs = AuditLog::query()->where('action', 'group_entry.accepted')->get();

    expect($logs->where('payload.group_entry_id', $first->id)->pluck('company_id')->sort()->values()->all())->toBe(collect([$this->a->id, $this->b->id])->sort()->values()->all())
        ->and($logs->where('payload.group_entry_id', $second->id)->pluck('company_id')->sort()->values()->all())->toBe(collect([$this->p->id, $this->a->id])->sort()->values()->all())
        ->and(array_keys($logs->first()->payload))->toEqualCanonicalizing(['corporate_group_id', 'group_entry_id', 'number', 'kind', 'type', 'reverses_id', 'rows', 'this_company'])
        ->and(e25Suggestion($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}")['accepted_entry'])->toBe(1);
});

it('applies accepted entries to a run of exactly their period, net to zero, and names them in the inputs', function () {
    $gross = e25Run($this);
    $entry = e25Accept($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");
    e25Accept($this, "RECEIVABLE_PAYABLE:{$this->b->id}:{$this->a->id}");
    e25Accept($this, "LOAN:{$this->a->id}:{$this->p->id}");
    $net = e25Run($this);

    expect(e25Figures($gross))->toMatchArray([1320 => 50_000, 1510 => 100_000, 2360 => 50_000, 2440 => 100_000, 3011 => 130_000, 4010 => -100_000])
        ->and(e25Figures($net))->toBe([1930 => 30_000, 3011 => 30_000])
        ->and($net->output['income']['result'])->toBe($gross->output['income']['result'])
        ->and($net->output['balance']['assets']['total'])->toBe($net->output['balance']['equity']['total'])
        ->and($net->output['eliminations'])->toBe(['entries' => 3, 'by_type' => ['LOAN' => 50_000, 'RECEIVABLE_PAYABLE' => 100_000, 'SALES_PURCHASES' => 100_000], 'entries_by_type' => ['LOAN' => 1, 'RECEIVABLE_PAYABLE' => 1, 'SALES_PURCHASES' => 1], 'pairs_by_type' => ['LOAN' => 1, 'RECEIVABLE_PAYABLE' => 1, 'SALES_PURCHASES' => 1]])
        ->and(array_column($net->inputs['group_entries'], 'hash'))->toContain($entry->hash)
        ->and($net->inputs['engine_version'])->toBe(2)
        ->and(app(ConsolidateGroup::class)->check($this->olle->fresh(), $this->group->id, $gross->id)['inputs_unchanged'])->toBeFalse()
        ->and(app(ConsolidateGroup::class)->check($this->olle->fresh(), $this->group->id, $net->id))->toBe(['inputs_unchanged' => true, 'output_reproduced' => true, 'stored_intact' => true]);

    // Another period does not get them.
    $half = e25Run($this, from: '2026-01-01', to: '2026-06-30');

    expect($half->inputs['group_entries'])->toBe([])
        ->and(e25Figures($half)[3011])->toBe(130_000);
});

it('never writes into a member company\'s books, and every ledger still verifies', function () {
    $ids = [$this->p->id, $this->a->id, $this->b->id];
    $snapshot = fn (): array => [
        DB::table('vouchers')->whereIn('company_id', $ids)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        DB::table('voucher_rows')->whereIn('company_id', $ids)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        DB::table('opening_balances')->whereIn('company_id', $ids)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
    ];
    $before = $snapshot();

    $entry = e25Accept($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");
    app(RecordGroupEntry::class)->reverse($this->olle->fresh(), $this->group->id, $entry->id, ['reason' => 'Fel period']);
    e25Run($this);

    expect($snapshot())->toBe($before);

    foreach ([$this->p, $this->a, $this->b] as $company) {
        $this->artisan('ledger:verify', ['company' => $company->org_number])->assertSuccessful();
    }
});

it('reverses an entry with a mirrored entry, once, and lets the suggestion be accepted again', function () {
    $key = "SALES_PURCHASES:{$this->a->id}:{$this->b->id}";
    $entry = e25Accept($this, $key);
    $reversal = app(RecordGroupEntry::class)->reverse($this->olle->fresh(), $this->group->id, $entry->id, ['reason' => 'Fel belopp']);

    expect($reversal)->toMatchArray(['number' => 2, 'reverses_id' => $entry->id, 'reason' => 'Fel belopp', 'total_ore' => 100_000])
        ->and($reversal->kind)->toBe(GroupEntryKind::Reversal)
        ->and($reversal->type)->toBe(EliminationType::SalesPurchases)
        ->and($reversal->rows->map(fn (GroupEntryRow $row): array => [$row->company_id, $row->group_account_number, $row->debit_ore, $row->credit_ore])->all())
        ->toBe([[$this->a->id, 3011, 0, 100_000], [$this->b->id, 4010, 100_000, 0]])
        ->and(e25Run($this)->output['eliminations'])->toBe(['entries' => 2, 'by_type' => [], 'entries_by_type' => ['SALES_PURCHASES' => 2], 'pairs_by_type' => []])
        ->and(e25Figures(e25Run($this))[3011])->toBe(130_000);

    expect(fn () => app(RecordGroupEntry::class)->reverse($this->olle->fresh(), $this->group->id, $entry->id, ['reason' => 'Igen']))->toThrow(ValidationException::class, 'Posten är redan återförd.')
        ->and(fn () => app(RecordGroupEntry::class)->reverse($this->olle->fresh(), $this->group->id, $reversal->id, ['reason' => 'Igen']))->toThrow(ValidationException::class, 'En återföring kan inte återföras.')
        ->and(fn () => app(RecordGroupEntry::class)->reverse($this->olle->fresh(), $this->group->id, $entry->id, ['reason' => '']))->toThrow(ValidationException::class, 'Skriv varför posten återförs.');

    expect(e25Suggestion($this, $key)['accepted_entry'])->toBeNull()
        ->and(e25Accept($this, $key)->number)->toBe(3);
});

it('refuses to accept a suggestion twice, or one that changed since it was shown', function () {
    $key = "SALES_PURCHASES:{$this->a->id}:{$this->b->id}";
    $seen = e25Suggestion($this, $key)['fingerprint'];
    $accept = fn (string $fingerprint) => app(RecordGroupEntry::class)->accept($this->olle->fresh(), $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'key' => $key, 'fingerprint' => $fingerprint]);

    // The books change after the page was shown: the person must look again.
    $more = e25Post($this->olle, $this->a, '2026-09-01', [1510 => 10_000, 3011 => -10_000]);
    e25Tag($this->a, $more, 3011, $this->b, 'SALES');
    $more = e25Post($this->olle, $this->b, '2026-09-01', [4010 => 10_000, 2440 => -10_000]);
    e25Tag($this->b, $more, 4010, $this->a, 'PURCHASES');

    expect(fn () => $accept($seen))->toThrow(ValidationException::class, 'Förslaget har ändrats sedan sidan visades.')
        ->and(fn () => $accept(str_repeat('0', 64)))->toThrow(ValidationException::class, 'Förslaget har ändrats sedan sidan visades.');

    $fresh = e25Suggestion($this, $key)['fingerprint'];
    $accept($fresh);

    expect(fn () => $accept($fresh))->toThrow(ValidationException::class, 'Förslaget är redan godkänt för perioden (post 1).')
        ->and(GroupEntry::query()->count())->toBe(1);
});

it('says so when the figures of an accepted suggestion have changed since', function () {
    $key = "SALES_PURCHASES:{$this->a->id}:{$this->b->id}";
    e25Accept($this, $key);
    $more = e25Post($this->olle, $this->a, '2026-09-01', [1510 => 10_000, 3011 => -10_000]);
    e25Tag($this->a, $more, 3011, $this->b, 'SALES');

    expect(e25Suggestion($this, $key))->toMatchArray(['accepted_entry' => 1, 'changed_since_accepted' => true, 'status' => EliminationSuggestions::MISMATCH]);
});

it('records a balanced manual entry for companies in the run, in Swedish', function () {
    $manual = fn (array $rows, string $type = 'OTHER', string $reason = 'Fordran från 2025') => app(RecordGroupEntry::class)->manual($this->olle->fresh(), $this->group->id, [
        'from' => '2026-01-01', 'to' => '2026-12-31', 'type' => $type, 'reason' => $reason, 'rows' => $rows,
    ]);

    expect(fn () => $manual([['company' => $this->a->id, 'account' => '2440', 'debit' => '100', 'credit' => ''], ['company' => $this->b->id, 'account' => '1510', 'debit' => '', 'credit' => '90']]))
        ->toThrow(ValidationException::class, 'Posten balanserar inte')
        ->and(fn () => $manual([['company' => $this->a->id, 'account' => '2440', 'debit' => '100', 'credit' => '100'], ['company' => $this->b->id, 'account' => '1510', 'debit' => '', 'credit' => '100']]))
        ->toThrow(ValidationException::class, 'Varje rad ska ha ett belopp i antingen debet eller kredit.')
        ->and(fn () => $manual([['company' => $this->a->id, 'account' => '2440', 'debit' => 'tusen', 'credit' => ''], ['company' => $this->b->id, 'account' => '1510', 'debit' => '', 'credit' => '100']]))
        ->toThrow(ValidationException::class, 'Beloppet tusen kan inte läsas.')
        ->and(fn () => $manual([['company' => $this->a->id, 'account' => '9999', 'debit' => '100', 'credit' => ''], ['company' => $this->b->id, 'account' => '1510', 'debit' => '', 'credit' => '100']]))
        ->toThrow(ValidationException::class, 'Ange ett koncernkonto mellan 1000 och 8999 på varje rad.')
        ->and(fn () => $manual([['company' => $this->a->id, 'account' => '2440', 'debit' => '100', 'credit' => ''], ['company' => $this->b->id, 'account' => '1510', 'debit' => '', 'credit' => '100']], reason: ''))
        ->toThrow(ValidationException::class, 'Skriv varför posten görs.');

    $outsider = User::factory()->withCompany()->create()->currentCompany();

    expect(fn () => $manual([['company' => $outsider->id, 'account' => '2440', 'debit' => '100', 'credit' => ''], ['company' => $this->b->id, 'account' => '1510', 'debit' => '', 'credit' => '100']]))
        ->toThrow(ValidationException::class, 'Ett företag på raderna ingår inte i konsolideringen för perioden.')
        ->and(GroupEntry::query()->count())->toBe(0);

    $entry = $manual([['company' => $this->a->id, 'account' => '2440', 'debit' => '1 000,50', 'credit' => ''], ['company' => $this->b->id, 'account' => '1510', 'debit' => '', 'credit' => '1000,50']], 'RECEIVABLE_PAYABLE');

    expect($entry)->toMatchArray(['number' => 1, 'total_ore' => 100_050, 'suggestion_key' => null, 'reason' => 'Fordran från 2025'])
        ->and($entry->kind)->toBe(GroupEntryKind::Elimination)
        ->and($entry->rows->pluck('account_number')->all())->toBe([null, null])
        ->and(e25Figures(e25Run($this))[2440])->toBe(100_000 - 100_050)
        ->and(AuditLog::query()->where('action', 'group_entry.recorded')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'group_entry.recorded')->get()->every(fn (AuditLog $log): bool => ! str_contains(json_encode($log->payload), 'Fordran')))->toBeTrue();
});

it('skips an entry that names a company the run does not read, and says so', function () {
    e25Accept($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");
    // B was sold before the year began: the period no longer reads it.
    CurrentCompany::run($this->p, fn () => CompanyHolding::query()->whereKey($this->holdingB->id)->update(['disposed_on' => '2026-01-01']));

    $run = e25Run($this);

    expect($run->inputs['group_entries'])->toBe([])
        ->and($run->inputs['group_entries_skipped'])->toHaveCount(1)
        ->and(e25Figures($run)[3011])->toBe(130_000)
        ->and(ConsolidationView::present($run, GroupAccessLevel::Full)['skipped_entries'])->toBe(1);
});

it('lets only whoever administers the group write, and shows suggestions with FULL access only', function () {
    $accountant = Bookkeeping::member($this->p, 'admin');
    $this->a->users()->attach($accountant, ['role' => 'accountant']);
    $this->b->users()->attach($accountant, ['role' => 'admin']);
    $key = "SALES_PURCHASES:{$this->a->id}:{$this->b->id}";

    // FULL access but not administrator in A.
    expect(GroupAccess::for($accountant->fresh())->levelFor($this->group))->toBe(GroupAccessLevel::Full)
        ->and(fn () => e25Accept($this, $key, $accountant))->toThrow(AuthorizationException::class, 'Bara den som är ägare eller administratör i alla företag i koncernen')
        ->and(fn () => app(RecordGroupEntry::class)->manual($accountant->fresh(), $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'type' => 'OTHER', 'reason' => 'x', 'rows' => []]))->toThrow(ValidationException::class);

    $entry = e25Accept($this, $key);

    expect(fn () => app(RecordGroupEntry::class)->reverse($accountant->fresh(), $this->group->id, $entry->id, ['reason' => 'Nej']))->toThrow(AuthorizationException::class);

    // The group role sees sums only: no suggestion, no entry, no write.
    $viewer = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);

    expect(fn () => e25Accept($this, $key, $viewer))->toThrow(AuthorizationException::class);

    // A stranger does not see the group at all.
    $stranger = User::factory()->withCompany()->create();

    expect(fn () => app(RecordGroupEntry::class)->reverse($stranger, $this->group->id, $entry->id, ['reason' => 'x']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RecordGroupEntry::class)->accept($stranger, $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'key' => $key, 'fingerprint' => str_repeat('0', 64)]))->toThrow(ModelNotFoundException::class);
});

it('gives a consolidated viewer elimination totals per type, never a company or an account', function () {
    e25Accept($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");
    $viewer = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);

    $run = e25Run($this, $viewer);
    $view = ConsolidationView::present($run, GroupAccessLevel::Consolidated);

    // One pair only: its total would be A's sales to B, so the viewer gets the number of entries (cold review P2-2).
    expect($view['eliminations'])->toBe([['label' => 'Försäljning och inköp', 'amount' => null, 'entries' => 1]])
        ->and(ConsolidationView::present($run, GroupAccessLevel::Full)['eliminations'])->toBe([['label' => 'Försäljning och inköp', 'amount' => 100_000, 'entries' => 1]])
        ->and($view['skipped_entries'])->toBe(0)
        ->and(json_encode($run->output))->not->toContain('company_id')
        ->and(json_encode($run->output))->not->toContain((string) $this->a->org_number);

    // A second pair of the same type: the total is shown.
    $sale = e25Post($this->olle, $this->p, '2026-07-01', [1510 => 20_000, 3011 => -20_000]);
    e25Tag($this->p, $sale, 3011, $this->a, 'SALES');
    $purchase = e25Post($this->olle, $this->a, '2026-07-01', [4010 => 20_000, 2440 => -20_000]);
    e25Tag($this->a, $purchase, 4010, $this->p, 'PURCHASES');
    e25Accept($this, "SALES_PURCHASES:{$this->p->id}:{$this->a->id}");

    expect(ConsolidationView::present(e25Run($this, $viewer), GroupAccessLevel::Consolidated)['eliminations'])->toBe([['label' => 'Försäljning och inköp', 'amount' => 120_000, 'entries' => 2]]);

    // Keep E24's rule: a run the viewer could subtract into one company's figures is still refused.
    CurrentCompany::run($this->p, fn () => CompanyHolding::query()->whereKey($this->holdingB->id)->update(['acquired_on' => '2026-03-01']));

    expect(fn () => e25Run($this, $viewer))->toThrow(ValidationException::class, 'Den här körningen kan inte visas');
});

it('suggests shares against share capital from the balances, only when wholly owned and equal', function () {
    $opening = function (Company $company, int $account, int $amount, int $balancing): void {
        CurrentCompany::run($company, function () use ($account, $amount, $balancing): void {
            $year = FiscalYear::query()->firstOrFail();
            $ids = Account::query()->pluck('id', 'number');
            e25AddOpening($year, $ids[$account], $amount);
            e25AddOpening($year, $ids[$balancing], -$amount);
        });
    };
    $opening($this->p, 1310, 100_000, 2081);
    // A started its books in NONOX: its share capital is a posted voucher, not an opening balance.
    e25Post($this->olle, $this->a, '2026-01-15', [1930 => 50_000, 2081 => -50_000]);
    $opening($this->b, 1930, 50_000, 2081);
    $key = "SHARES_EQUITY:{$this->p->id}";

    // Two members held by P, as 70/30 and 30/70 or any split: P's one share balance cannot be split per member (cold review P2-1).
    expect(e25Suggestion($this, $key))->toMatchArray(['status' => EliminationSuggestions::NEEDS_ACQUISITION_ANALYSIS, 'difference' => 0, 'founded' => false, 'rows' => []])
        ->and(fn () => e25Accept($this, $key))->toThrow(ValidationException::class, 'Förslaget kräver en förvärvsanalys');

    // B sold before the year: P holds one member, founded and wholly owned, with equal sides.
    CurrentCompany::run($this->p, fn () => CompanyHolding::query()->whereKey($this->holdingB->id)->update(['disposed_on' => '2025-12-31']));
    $opening($this->p, 1310, -50_000, 1930);
    $shares = e25Suggestion($this, $key);

    expect($shares)->toMatchArray(['status' => EliminationSuggestions::MATCHED, 'difference' => 0, 'total_ore' => 50_000, 'wholly_owned' => true, 'founded' => true])
        ->and($shares['credit']['companies'])->toBe([$this->a->id => 50_000])
        ->and(collect($shares['rows'])->map(fn (array $row): array => [$row['company_id'], $row['group_account_number'], $row['debit_ore'], $row['credit_ore']])->all())
        ->toBe([[$this->a->id, 2081, 50_000, 0], [$this->p->id, 1310, 0, 50_000]]);

    e25Accept($this, $key);
    $run = e25Run($this);

    expect(e25Figures($run))->not->toHaveKey(1310);

    // A holding of less than all of the capital needs an acquisition analysis (stage 2).
    CurrentCompany::run($this->p, fn () => CompanyHolding::query()->whereKey($this->holdingA->id)->update(['capital_bp' => 7000]));

    expect(e25Suggestion($this, $key))->toMatchArray(['status' => EliminationSuggestions::NEEDS_ACQUISITION_ANALYSIS, 'wholly_owned' => false, 'rows' => []]);

    // A company bought after its first fiscal year started: its equity at the acquisition is not its share capital.
    CurrentCompany::run($this->p, fn () => CompanyHolding::query()->whereKey($this->holdingA->id)->update(['capital_bp' => 10_000, 'acquired_on' => '2026-02-01']));

    expect(e25Suggestion($this, $key))->toMatchArray(['status' => EliminationSuggestions::NEEDS_ACQUISITION_ANALYSIS, 'wholly_owned' => true, 'founded' => false]);
});

it('never shows a consolidated viewer the amount of OTHER, nor counts an entry within one company as a pair', function () {
    $viewer = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    $manual = fn (string $type, Company $first, Company $second) => app(RecordGroupEntry::class)->manual($this->olle->fresh(), $this->group->id, [
        'from' => '2026-01-01', 'to' => '2026-12-31', 'type' => $type, 'reason' => 'Prov', 'rows' => [
            ['company' => $first->id, 'account' => '3011', 'debit' => '10', 'credit' => ''],
            ['company' => $second->id, 'account' => '4010', 'debit' => '', 'credit' => '10'],
        ],
    ]);

    // OTHER between two different pairs: still only the number of entries.
    $manual('OTHER', $this->a, $this->b);
    $manual('OTHER', $this->p, $this->a);
    // One pair of sales plus an entry within A alone: one pair, so no amount either.
    e25Accept($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");
    $manual('SALES_PURCHASES', $this->a, $this->a);

    $run = e25Run($this, $viewer);

    expect($run->output['eliminations']['pairs_by_type'])->toBe(['OTHER' => 2, 'SALES_PURCHASES' => 1])
        ->and(ConsolidationView::present($run, GroupAccessLevel::Consolidated)['eliminations'])->toBe([
            ['label' => 'Övrig justering', 'amount' => null, 'entries' => 2],
            ['label' => 'Försäljning och inköp', 'amount' => null, 'entries' => 2],
        ])
        ->and(ConsolidationView::present($run, GroupAccessLevel::Full)['eliminations'][0])->toBe(['label' => 'Övrig justering', 'amount' => 2_000, 'entries' => 2]);
});

it('never matches shares against equity for a company moved to NONOX from another program', function () {
    // A was bought in 2015; its first NONOX year opens with share capital and retained earnings. 1310 equals 2081.
    CurrentCompany::run($this->p, fn () => CompanyHolding::query()->whereKey($this->holdingA->id)->update(['acquired_on' => '2015-03-01']));
    CurrentCompany::run($this->p, fn () => CompanyHolding::query()->whereKey($this->holdingB->id)->update(['disposed_on' => '2025-12-31']));
    e25Accounts($this->a, [2091]);

    foreach ([[$this->p, [1310 => 50_000, 2081 => -50_000]], [$this->a, [1930 => 250_000, 2081 => -50_000, 2091 => -200_000]]] as [$company, $balances]) {
        CurrentCompany::run($company, function () use ($balances): void {
            $year = FiscalYear::query()->firstOrFail();
            $ids = Account::query()->pluck('id', 'number');

            foreach ($balances as $account => $amount) {
                e25AddOpening($year, $ids[$account], $amount);
            }
        });
    }

    expect(e25Suggestion($this, "SHARES_EQUITY:{$this->p->id}"))->toMatchArray(['status' => EliminationSuggestions::NEEDS_ACQUISITION_ANALYSIS, 'difference' => 0, 'wholly_owned' => true, 'founded' => false, 'rows' => []]);
});

it('suggests receivables and loans as closing balances over every year, and asks for review of an untagged opening balance', function () {
    // A loan given in 2025, with no movement in 2026, still gets its suggestion.
    foreach ([$this->p, $this->a] as $company) {
        CurrentCompany::run($company, fn () => FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'OPEN']));
    }

    $old = e25Post($this->olle, $this->p, '2025-06-01', [1320 => 30_000, 1930 => -30_000]);
    e25Tag($this->p, $old, 1320, $this->a, 'LOAN_RECEIVABLE');
    $old = e25Post($this->olle, $this->a, '2025-06-01', [1930 => 30_000, 2360 => -30_000]);
    e25Tag($this->a, $old, 2360, $this->p, 'LOAN_PAYABLE');

    // The year-end rollover carries the 2025 balances into 2026's opening balances, as the closing does.
    foreach ([[$this->p, [1320 => 30_000, 1930 => -30_000]], [$this->a, [1930 => 30_000, 2360 => -30_000]]] as [$company, $balances]) {
        CurrentCompany::run($company, function () use ($balances): void {
            $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
            $ids = Account::query()->pluck('id', 'number');

            foreach ($balances as $account => $amount) {
                e25AddOpening($year, $ids[$account], $amount);
            }
        });
    }

    expect(e25Suggestion($this, "LOAN:{$this->a->id}:{$this->p->id}"))->toMatchArray(['status' => EliminationSuggestions::MATCHED, 'total_ore' => 80_000]);

    // B owed A 1 500 kr from before NONOX (an opening balance, never tagged) and paid it this year, next to the new 1 000 kr.
    foreach ([[$this->a, 1510], [$this->b, 2440]] as [$company, $account]) {
        CurrentCompany::run($company, function () use ($account): void {
            $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
            $ids = Account::query()->pluck('id', 'number');
            $amount = $account === 1510 ? 150_000 : -150_000;
            e25AddOpening($year, $ids[$account], $amount);
            e25AddOpening($year, $ids[1930], -$amount);
        });
    }
    $paid = e25Post($this->olle, $this->b, '2026-06-01', [2440 => 150_000, 1930 => -150_000]);
    e25Tag($this->b, $paid, 2440, $this->a, 'PAYABLE');
    $paid = e25Post($this->olle, $this->a, '2026-06-01', [1930 => 150_000, 1510 => -150_000]);
    e25Tag($this->a, $paid, 1510, $this->b, 'RECEIVABLE');

    $key = "RECEIVABLE_PAYABLE:{$this->b->id}:{$this->a->id}";
    $debts = e25Suggestion($this, $key);

    // The tags alone say −500 on both sides; the real closing balance is 1 000. Never matched on that.
    expect($debts)->toMatchArray(['status' => EliminationSuggestions::NEEDS_REVIEW, 'rows' => []])
        ->and($debts['debit']['accounts'])->toBe([1510 => -50_000])
        ->and(fn () => e25Accept($this, $key))->toThrow(ValidationException::class, 'ingående balans utan koncernmarkering')
        ->and(e25Figures(e25Run($this))[1510])->toBe(100_000);
});

it('shows nothing to eliminate for a pair that nets to zero, and refuses it', function () {
    e25Accounts($this->p, [8250, 8251]);
    $voucher = e25Post($this->olle, $this->p, '2026-08-01', [8251 => 5_000, 8250 => -5_000]);
    e25Tag($this->p, $voucher, 8250, $this->b, 'INTEREST_INCOME');
    e25Tag($this->p, $voucher, 8251, $this->b, 'INTEREST_INCOME');
    $key = "INTEREST:{$this->p->id}:{$this->b->id}";

    expect(e25Suggestion($this, $key))->toMatchArray(['status' => EliminationSuggestions::NOTHING, 'rows' => []])
        ->and(fn () => e25Accept($this, $key))->toThrow(ValidationException::class, 'Förslaget har inget att eliminera.');
});

it('keeps the group ledger append-only in the model and in the database', function () {
    $entry = e25Accept($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");
    $row = $entry->rows->first();

    expect(fn () => $entry->forceFill(['reason' => 'Ändrad'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(fn () => $row->forceFill(['debit_ore' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);

    foreach ([
        fn () => DB::table('group_entries')->where('id', $entry->id)->update(['reason' => 'Ändrad']),
        fn () => DB::table('group_entries')->where('id', $entry->id)->delete(),
        fn () => DB::table('group_entry_rows')->where('id', $row->id)->update(['debit_ore' => 1]),
        fn () => DB::table('group_entry_rows')->where('id', $row->id)->delete(),
    ] as $attempt) {
        e25Savepoint(fn () => expect($attempt)->toThrow(Exception::class, 'Group ledger entries cannot be changed or deleted.'));
    }
});

it('refuses at commit an entry that does not balance, lacks its rows or gets a row later', function () {
    $entry = e25Accept($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");
    $insert = fn (array $values): int => DB::table('group_entries')->insertGetId([
        'corporate_group_id' => $this->group->id, 'number' => 99, 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'entry_date' => '2026-12-31',
        'kind' => 'ADJUSTMENT', 'type' => 'OTHER', 'origin' => 'MANUAL', 'rows_count' => 2, 'total_ore' => 100, 'accepted_by' => $this->olle->id,
        'accepted_at' => now(), 'hash' => str_repeat('a', 64), 'created_at' => now(), ...$values,
    ]);
    $commit = fn () => DB::statement('SET CONSTRAINTS group_entries_balanced, group_entry_rows_balanced IMMEDIATE');

    // The accepted entry itself passes the check that runs at commit.
    e25Savepoint(fn () => expect($commit())->toBeTrue());

    e25Savepoint(function () use ($insert, $commit): void {
        $id = $insert([]);
        DB::table('group_entry_rows')->insert([
            ['group_entry_id' => $id, 'company_id' => $this->a->id, 'group_account_number' => 3011, 'debit_ore' => 100, 'credit_ore' => 0],
            ['group_entry_id' => $id, 'company_id' => $this->b->id, 'group_account_number' => 4010, 'debit_ore' => 0, 'credit_ore' => 90],
        ]);
        expect($commit)->toThrow(Exception::class, 'does not balance');
    });

    e25Savepoint(function () use ($insert, $commit): void {
        $insert(['number' => 98]);
        expect($commit)->toThrow(Exception::class, 'does not balance');
    });

    e25Savepoint(function () use ($entry, $commit): void {
        DB::table('group_entry_rows')->insert([
            ['group_entry_id' => $entry->id, 'company_id' => $this->a->id, 'group_account_number' => 3011, 'debit_ore' => 5, 'credit_ore' => 0],
            ['group_entry_id' => $entry->id, 'company_id' => $this->b->id, 'group_account_number' => 4010, 'debit_ore' => 0, 'credit_ore' => 5],
        ]);
        expect($commit)->toThrow(Exception::class, 'does not balance');
    });

    e25Savepoint(fn () => expect(fn () => DB::table('group_entry_rows')->insert(['group_entry_id' => $entry->id, 'company_id' => $this->a->id, 'group_account_number' => 3011, 'debit_ore' => 5, 'credit_ore' => 5]))->toThrow(Exception::class, 'group_entry_rows_one_sided'));
});

it('accepts, records, reverses and checks through the page with Swedish toasts', function () {
    $this->actingAs($this->olle->fresh());
    $key = "SALES_PURCHASES:{$this->a->id}:{$this->b->id}";

    $page = Livewire::test(EliminationsPage::class)
        ->assertOk()
        ->assertSet('group', (string) $this->group->id)
        ->assertSet('from', '2026-01-01')
        ->assertSet('to', '2026-12-31')
        ->assertSee('Intern koncernrapport, inte en koncernredovisning enligt ÅRL')
        ->assertSee('Försäljning och inköp')
        ->assertSee('Matchar')
        ->assertSee('Inga poster för perioden ännu.')
        ->call('accept', $key)
        ->assertDispatched('toast', message: 'Elimineringen är godkänd som post 1 i koncernjournalen. Den dras från nästa konsolidering av perioden.', type: 'success')
        ->assertSee('Godkänd, post 1')
        ->call('accept', $key)
        ->assertDispatched('toast', message: 'Förslaget är redan godkänt för perioden (post 1). Återför posten först om den ska göras om.', type: 'error');

    $page->set('manualType', 'RECEIVABLE_PAYABLE')
        ->set('manualReason', 'Fordran från 2025')
        ->set('manualRows', [
            ['company' => (string) $this->a->id, 'account' => '2440', 'debit' => '200', 'credit' => ''],
            ['company' => (string) $this->b->id, 'account' => '1510', 'debit' => '', 'credit' => '200'],
        ])
        ->call('saveManual')
        ->assertDispatched('toast', message: 'Posten är sparad som nummer 2 i koncernjournalen. Den dras från nästa konsolidering av perioden.', type: 'success')
        ->assertSet('manualReason', '');

    $page->call('startReverse', GroupEntry::query()->where('number', 1)->value('id'))
        ->set('reverseReason', 'Fel period')
        ->call('reverse')
        ->assertDispatched('toast', message: 'Posten är återförd med post 3. Båda finns kvar i koncernjournalen.', type: 'success')
        ->assertSee('Återförd med post 3.')
        ->call('verifyLedger')
        ->assertDispatched('toast', message: 'Koncernjournalen stämmer: 3 poster, utan luckor och med oförändrade kontrollsummor.', type: 'success');
});

it('shows a consolidated viewer no suggestion or entry, and answers 404 for a group the user does not see', function () {
    e25Accept($this, "SALES_PURCHASES:{$this->a->id}:{$this->b->id}");
    $viewer = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    $this->actingAs($viewer->fresh());

    Livewire::test(EliminationsPage::class)
        ->assertOk()
        ->assertSee('Elimineringarna visas inte för dig')
        ->assertDontSee('Matchar')
        ->assertDontSee('Post 1')
        ->assertDontSee('Dotter A Exempel AB')
        ->call('verifyLedger')
        ->assertDispatched('toast', message: 'Koncernjournalen kan bara kontrolleras av den som får se rapporter i alla koncernens företag.', type: 'error');

    $this->actingAs(User::factory()->withCompany()->create());
    $this->get(route('group.eliminations', ['koncern' => $this->group->id]))->assertNotFound();
});

it('loads the page in the same number of queries for a short and a long history', function () {
    $this->actingAs($this->olle->fresh());

    $count = function (int $extra): int {
        foreach (range(1, $extra) as $day) {
            $sale = e25Post($this->olle, $this->a, sprintf('2026-07-%02d', $day), [1510 => 1_000, 3011 => -1_000]);
            e25Tag($this->a, $sale, 3011, $this->b, 'SALES');
            $purchase = e25Post($this->olle, $this->b, sprintf('2026-07-%02d', $day), [4010 => 1_000, 2440 => -1_000]);
            e25Tag($this->b, $purchase, 4010, $this->a, 'PURCHASES');
            app(RecordGroupEntry::class)->manual($this->olle->fresh(), $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'type' => 'OTHER', 'reason' => 'Prov', 'rows' => [
                ['company' => $this->a->id, 'account' => '3011', 'debit' => '1', 'credit' => ''],
                ['company' => $this->b->id, 'account' => '4010', 'debit' => '', 'credit' => '1'],
            ]]);
        }

        $page = Livewire::test(EliminationsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count(1);
    $long = $count(15);

    expect($long)->toBe($short);
});
