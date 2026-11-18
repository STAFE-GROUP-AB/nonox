<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\RecordGroupEntry;
use App\Actions\Group\RecordHolding;
use App\Actions\Group\RemoveGroupMember;
use App\Domain\Accounting\AccountType;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\EliminationSuggestions;
use App\Domain\Group\GroupAccess;
use App\Livewire\Group\EliminationsPage;
use App\Models\Account;
use App\Models\Company;
use App\Models\GroupEntry;
use App\Models\IntercompanyRowTag;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * The group ledger never leaks (ADR-081 E25, AGENTS.md §1.3). Group 1 is P
 * with subsidiary A. Outside it: company X, where the same administrator is
 * a member and which A trades with, and a second group Q with subsidiary R
 * and an entry of its own that the administrator has nothing to do with.
 * Only the companies the period reads are ever matched or named; another
 * group, and another group's entry, answer 404.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
    $this->olle = User::factory()->withCompany()->create();
    $this->p = $this->olle->currentCompany();
    app(InitialiseBookkeeping::class)($this->p, $this->olle, CarbonImmutable::parse('2026-01-15'));
    $this->a = e25TenancyCompany($this->olle);
    $this->x = e25TenancyCompany($this->olle);
    e25TenancyHolding($this->olle, $this->p, $this->a);
    // X is held by P but never confirmed: a holding alone never brings a company into the suggestions.
    e25TenancyHolding($this->olle, $this->p, $this->x);
    $this->group = app(CreateCorporateGroup::class)($this->olle->fresh(), $this->p, 'Koncernen');
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a);

    // A sells to P and to X; P and X both tag their purchase.
    foreach ([$this->p, $this->x] as $buyer) {
        e25TenancyTagged($this->olle, $this->a, [1510 => 10_000, 3001 => -10_000], 3001, $buyer, 'SALES');
        e25TenancyTagged($this->olle, $buyer, [4010 => 10_000, 2440 => -10_000], 4010, $this->a, 'PURCHASES');
    }

    $this->stranger = User::factory()->withCompany()->create();
    $this->q = $this->stranger->currentCompany();
    app(InitialiseBookkeeping::class)($this->q, $this->stranger, CarbonImmutable::parse('2026-01-15'));
    $this->r = e25TenancyCompany($this->stranger);
    e25TenancyHolding($this->stranger, $this->q, $this->r);
    $this->otherGroup = app(CreateCorporateGroup::class)($this->stranger->fresh(), $this->q, 'Främmande koncernen');
    app(ConfirmGroupMember::class)($this->stranger->fresh(), $this->otherGroup->id, $this->r);
    $this->foreignEntry = app(RecordGroupEntry::class)->manual($this->stranger->fresh(), $this->otherGroup->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'type' => 'OTHER', 'reason' => 'Främmande', 'rows' => [
        ['company' => $this->q->id, 'account' => '3001', 'debit' => '5', 'credit' => ''],
        ['company' => $this->r->id, 'account' => '4010', 'debit' => '', 'credit' => '5'],
    ]]);

    $this->olle = $this->olle->fresh();
});

function e25TenancyCompany(User $user): Company
{
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse('2026-01-15'));
    $company->users()->attach($user, ['role' => 'admin']);

    return $company;
}

function e25TenancyHolding(User $user, Company $holder, Company $held): void
{
    app(RecordHolding::class)($user->fresh(), $holder, [
        'held_org_number' => $held->org_number, 'held_name' => $held->name, 'capital' => '100', 'voting' => '100',
        'control_basis' => ControlBasis::Votes->value, 'acquired_on' => '2024-01-01',
    ]);
}

/** @param  array<int, int>  $rows */
function e25TenancyTagged(User $user, Company $company, array $rows, int $account, Company $counterparty, string $kind): void
{
    CurrentCompany::run($company, function () use ($user, $company, $rows, $account, $counterparty, $kind): void {
        foreach (array_keys($rows) as $number) {
            if (! Account::query()->where('number', $number)->exists()) {
                Account::create(['number' => $number, 'name' => "Konto {$number}", 'type' => AccountType::forNumber($number), 'is_active' => true]);
            }
        }

        /** @var Voucher $voucher */
        $voucher = Bookkeeping::postRows($user->fresh(), $company, '2026-03-01', $rows);
        $row = $voucher->rows()->whereHas('account', fn ($query) => $query->where('number', $account))->firstOrFail();
        IntercompanyRowTag::create(['voucher_row_id' => $row->id, 'counterparty_org_number' => $counterparty->org_number, 'kind' => $kind, 'source' => 'MANUAL']);
    });
}

it('matches only the companies the period reads, never one outside the group', function () {
    $before = CurrentCompany::id();
    $result = app(EliminationSuggestions::class)->compute(GroupAccess::for($this->olle), $this->group, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));

    expect($result['companies'])->toEqualCanonicalizing([$this->p->id, $this->a->id])
        ->and(collect($result['suggestions'])->pluck('key')->all())->toBe(["SALES_PURCHASES:{$this->a->id}:{$this->p->id}"])
        ->and(json_encode($result))->not->toContain((string) $this->x->id.':')
        ->and(CurrentCompany::id())->toBe($before);
});

it('refuses a manual row naming a company outside the group', function () {
    expect(fn () => app(RecordGroupEntry::class)->manual($this->olle, $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'type' => 'OTHER', 'reason' => 'Prov', 'rows' => [
        ['company' => $this->x->id, 'account' => '3001', 'debit' => '5', 'credit' => ''],
        ['company' => $this->a->id, 'account' => '4010', 'debit' => '', 'credit' => '5'],
    ]]))->toThrow(ValidationException::class, 'Ett företag på raderna ingår inte i konsolideringen för perioden.');
});

it('answers 404 for another group and for another group\'s entry', function () {
    expect(fn () => app(RecordGroupEntry::class)->reverse($this->olle, $this->otherGroup->id, $this->foreignEntry->id, ['reason' => 'x']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RecordGroupEntry::class)->reverse($this->olle, $this->group->id, $this->foreignEntry->id, ['reason' => 'x']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RecordGroupEntry::class)->manual($this->olle, $this->otherGroup->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'type' => 'OTHER', 'reason' => 'x', 'rows' => [
            ['company' => $this->q->id, 'account' => '3001', 'debit' => '5', 'credit' => ''],
            ['company' => $this->r->id, 'account' => '4010', 'debit' => '', 'credit' => '5'],
        ]]))->toThrow(ModelNotFoundException::class)
        ->and(GroupEntry::query()->where('corporate_group_id', $this->otherGroup->id)->count())->toBe(1);

    $this->actingAs($this->olle);
    $this->get(route('group.eliminations', ['koncern' => $this->otherGroup->id]))->assertNotFound();

    $page = Livewire::test(EliminationsPage::class)->assertOk()->assertDontSee('Främmande');
    // A group id sent back by the browser is re-resolved: the page forgets it, and an action on it answers 404.
    $page->set('group', (string) $this->otherGroup->id)
        ->assertSet('group', '')
        ->assertDontSee('Främmande');
    $page->set('group', (string) $this->otherGroup->id)->set('reversing', $this->foreignEntry->id)->set('reverseReason', 'x')->call('reverse')->assertStatus(404);

    expect(GroupEntry::query()->where('reverses_id', $this->foreignEntry->id)->exists())->toBeFalse();
});

it('shows a former member\'s entries to nobody who no longer sees that company, and refuses to reverse them', function () {
    $entry = app(RecordGroupEntry::class)->accept($this->olle, $this->group->id, [
        'from' => '2026-01-01', 'to' => '2026-12-31', 'key' => "SALES_PURCHASES:{$this->a->id}:{$this->p->id}",
        'fingerprint' => collect(app(EliminationSuggestions::class)->compute(GroupAccess::for($this->olle), $this->group, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'))['suggestions'])->firstWhere('key', "SALES_PURCHASES:{$this->a->id}:{$this->p->id}")['fingerprint'],
    ]);
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->x);

    // A withdraws its consent; Kim administers P and X only, so Kim has FULL access to the group without A.
    app(RemoveGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a->id);
    $kim = Bookkeeping::member($this->p, 'admin');
    $this->x->users()->attach($kim, ['role' => 'admin']);
    $kim = $kim->fresh();

    $this->actingAs($kim);
    Livewire::test(EliminationsPage::class, ['group' => (string) $this->group->id])
        ->assertOk()
        ->assertSee('Post 1 gäller ett företag som du inte har åtkomst till.')
        ->assertDontSee('100,00')
        ->assertDontSee('3001')
        ->assertDontSee($this->a->name)
        ->set('reversing', $entry->id)
        ->set('reverseReason', 'Prov')
        ->call('reverse')
        ->assertDispatched('toast', message: 'Posten gäller ett företag som du inte har åtkomst till och kan inte återföras av dig.', type: 'error');

    expect(fn () => app(RecordGroupEntry::class)->reverse($kim, $this->group->id, $entry->id, ['reason' => 'Prov']))->toThrow(AuthorizationException::class)
        ->and(GroupEntry::query()->where('reverses_id', $entry->id)->exists())->toBeFalse();
});
