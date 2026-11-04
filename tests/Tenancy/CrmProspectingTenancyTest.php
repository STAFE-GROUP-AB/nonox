<?php

declare(strict_types=1);

use App\Models\RegistryChange;
use App\Models\RegistryCompany;
use App\Models\RegistryImport;
use App\Modules\Crm\Actions\AddProspects;
use App\Modules\Crm\Actions\CreateCallList;
use App\Modules\Crm\Actions\CreateRegistryNotices;
use App\Modules\Crm\Actions\WorkCallList;
use App\Modules\Crm\Livewire\CallListPage;
use App\Modules\Crm\Livewire\CallListsPage;
use App\Modules\Crm\Livewire\ProspectingPage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CallList;
use App\Modules\Crm\Models\CallListItem;
use App\Modules\Crm\Models\RegistryNotice;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for CRM stage 4 (ADR-047). The company register is
 * instance data and every CRM user sees all of it (public data); a
 * company's call lists, their items and the monitoring notices are its own:
 * B's ids answer 404, B's accounts never reach A's list, "already in the
 * CRM" reads only A's accounts, and monitoring writes in each company only.
 */

function tenancyOrg(int $serial): string
{
    $nine = '55999962'.$serial;

    return $nine.Luhn::checkDigit($nine);
}

beforeEach(function () {
    DB::table('company_registry_changes')->delete();
    DB::table('company_registry')->delete();
    DB::table('company_registry_imports')->delete();
    RegistryImport::create(['source' => 'scb-bulk', 'status' => RegistryImport::DONE, 'started_at' => now(), 'finished_at' => now()]);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));

    foreach ([1 => 'Delad Exempel AB', 2 => 'Bara B Exempel AB'] as $serial => $name) {
        RegistryCompany::create(['org_number' => tenancyOrg($serial), 'name' => $name, 'legal_form' => '49', 'scb_status' => 1, 'sni_code' => '62100', 'sni_division' => '62', 'city' => 'STOCKHOLM', 'sources' => ['scb-bulk']]);
    }

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        Crm::enable($company, $owner);
        $account = Crm::prospect($company, $owner, "{$name} Prospekt AB");
        $list = app(CreateCallList::class)($owner, $company, ['name' => "{$name}listan"], [$account->id])['list'];
        $this->{$side} = compact('owner', 'company', 'account', 'list');
    }

    // B already has "Bara B" as an account; A does not.
    CurrentCompany::set($this->b['company']);
    $this->b['registered'] = Crm::prospect($this->b['company'], $this->b['owner'], 'Bara B Exempel AB', ['org_number' => tenancyOrg(2)]);

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('shows the whole register to every company, minus only its own accounts', function () {
    Livewire::test(ProspectingPage::class)->set('division', '62')
        ->assertSee('Delad Exempel AB')
        ->assertSee('Bara B Exempel AB');

    CurrentCompany::set($this->b['company']);
    $this->actingAs($this->b['owner']);
    Livewire::test(ProspectingPage::class)->set('division', '62')
        ->assertSee('Delad Exempel AB')
        ->assertDontSee('Bara B Exempel AB');
});

it('adds a register company to A without seeing B\'s account for it', function () {
    $result = app(AddProspects::class)($this->a['owner'], $this->a['company'], [tenancyOrg(2)]);

    expect($result['added'])->toHaveCount(1)
        ->and($result['existing'])->toBe([])
        ->and(Account::query()->where('name', 'Bara B Exempel AB')->value('company_id'))->toBe($this->a['company']->id);
});

it('never shows B\'s call lists and answers 404 for B\'s list', function () {
    expect(CallList::query()->pluck('name')->all())->toBe(['Alfalistan'])
        ->and(CallListItem::query()->count())->toBe(1);

    Livewire::test(CallListsPage::class)->assertSee('Alfalistan')->assertDontSee('Betalistan');
    $this->get(route('crm.call-lists.show', $this->a['list']))->assertOk();
    $this->get(route('crm.call-lists.show', $this->b['list']))->assertNotFound();
    Livewire::test(CallListPage::class, ['callListId' => $this->b['list']->id])->assertNotFound();
});

it('refuses B\'s accounts on A\'s list and B\'s items in A\'s work', function () {
    expect(fn () => app(CreateCallList::class)($this->a['owner'], $this->a['company'], ['name' => 'Tvärs'], [$this->b['account']->id]))
        ->toThrow(ValidationException::class);

    $bItem = CurrentCompany::run($this->b['company'], fn (): CallListItem => CallListItem::query()->sole());

    expect(fn () => app(WorkCallList::class)->call($this->a['owner'], $this->a['company'], $bItem, ['outcome' => 'REACHED']))
        ->toThrow(ValidationException::class);

    CurrentCompany::set($this->b['company']);
    expect($bItem->fresh()?->status)->toBe(CallListItem::PENDING)
        ->and(Activity::query()->count())->toBe(0);
});

it('writes monitoring tasks only in the company that has the account', function () {
    RegistryChange::create(['org_number' => tenancyOrg(2), 'field' => 'name', 'old_value' => 'Bara B Exempel AB', 'new_value' => 'Nytt namn Exempel AB', 'source' => 'scb-bulk', 'detected_at' => now()->addMinute()]);
    $this->travel(2)->minutes();

    expect(app(CreateRegistryNotices::class)($this->a['company']))->toBe(0)
        ->and(app(CreateRegistryNotices::class)($this->b['company']))->toBe(1)
        ->and(RegistryNotice::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);

    CurrentCompany::set($this->b['company']);
    expect(RegistryNotice::query()->count())->toBe(1)
        ->and(Activity::query()->where('account_id', $this->b['registered']->id)->count())->toBe(1);
});
