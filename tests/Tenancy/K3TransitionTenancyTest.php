<?php

declare(strict_types=1);

use App\Actions\AnnualReport\BookK3Transition;
use App\Actions\AnnualReport\K3TransitionItems;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\K3TransitionPage;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\K3TransitionItem;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the K2-to-K3 transition (ADR-059 A.2.8, A.5, S1-P12):
 * company A never sees B's transition items, through Eloquent, the proposal,
 * the actions or the page; B's ids answer 404.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00:00'));

    foreach (['a' => 'Avsättning A', 'b' => 'Hemlig avsättning B'] as $side => $description) {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['legal_form' => LegalForm::Ab])->save();
        $this->actingAs($owner);
        CurrentCompany::set($company);
        $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
        $year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
        Account::create(['number' => 2220, 'name' => 'Avsättningar för garantier', 'type' => AccountType::forNumber(2220)]);
        $item = app(K3TransitionItems::class)->save($owner, $year->refresh(), [
            'kind' => K3TransitionItem::PROVISION, 'description' => $description, 'account' => '2220', 'amount' => '150 000',
        ]);
        $this->{$side} = compact('owner', 'company', 'year', 'item');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s items through Eloquent or the proposal', function () {
    expect(K3TransitionItem::query()->pluck('id')->all())->toBe([$this->a['item']->id]);

    $proposal = K3TransitionProposal::for($this->a['company'], $this->a['year']);

    expect(array_map(fn (array $line): int => $line['item']->id, $proposal->lines))->toBe([$this->a['item']->id])
        ->and($proposal->equityEffectOre())->toBe(-15_000_000);
});

it('answers 404 for B\'s item in the actions and the page', function () {
    $items = app(K3TransitionItems::class);

    expect(fn () => $items->save($this->a['owner'], $this->a['year'], ['id' => $this->b['item']->id, 'kind' => K3TransitionItem::PROVISION, 'description' => 'x', 'account' => '2220', 'amount' => '1']))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $items->remove($this->a['owner'], $this->a['year'], $this->b['item']->id))->toThrow(ModelNotFoundException::class);

    Livewire::test(K3TransitionPage::class, ['year' => $this->a['year']->id])->call('edit', $this->b['item']->id)->assertNotFound();
    $this->get(route('accounting.closing.k3Transition', ['ar' => $this->b['year']->id]))->assertOk()->assertDontSee('Hemlig avsättning B');
});

it('refuses A\'s user on B\'s fiscal year', function () {
    expect(fn () => app(K3TransitionItems::class)->save($this->a['owner'], $this->b['year'], ['kind' => K3TransitionItem::PROVISION, 'description' => 'x', 'account' => '2220', 'amount' => '1']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(BookK3Transition::class)($this->a['owner'], $this->b['year'], 'x'))->toThrow(AuthorizationException::class);
});
