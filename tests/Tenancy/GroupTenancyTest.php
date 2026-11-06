<?php

declare(strict_types=1);

use App\Actions\Group\ManageCompanyGroup;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupOverview;
use App\Livewire\Group\GroupOverviewPage;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMark;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the group overview (ADR-060). Two users each have a
 * group. Ann sees companies A1, A2 and the secret S through her memberships;
 * Bo owns his own group over B1 and S. A user sees a company's figures only
 * while she is a member with reports:view in THAT company, whatever a group
 * row says.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    $this->ann = User::factory()->create(['name' => 'Ann']);
    $this->bo = User::factory()->create(['name' => 'Bo']);

    foreach (['a1' => 'Annas Ett AB', 'a2' => 'Annas Två AB', 's' => 'Hemligt Bolag AB', 'b1' => 'Bos Bolag AB'] as $key => $name) {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['name' => $name])->save();
        $amount = $key === 's' ? 9_999_900 : 7_777_700;
        CurrentCompany::run($company, fn () => Bookkeeping::postRows($owner, $company, '2026-03-01', [1930 => $amount, 3001 => -$amount]));
        $this->{$key} = $company;
    }

    foreach ([$this->a1, $this->a2, $this->s] as $company) {
        $company->users()->attach($this->ann, ['role' => 'accountant']);
    }

    foreach ([$this->b1, $this->s] as $company) {
        $company->users()->attach($this->bo, ['role' => 'accountant']);
    }

    $manage = app(ManageCompanyGroup::class);
    $this->annGroup = $manage->create($this->ann, 'Annas koncern');
    $this->boGroup = $manage->create($this->bo, 'Bos koncern');

    foreach ([$this->a1, $this->a2, $this->s] as $company) {
        $manage->addCompany($this->ann, $this->annGroup->id, $company->id);
    }

    foreach ([$this->b1, $this->s] as $company) {
        $manage->addCompany($this->bo, $this->boGroup->id, $company->id);
    }

    $manage->mark($this->ann, $this->annGroup->id, $this->s->id, 3001, 'SALES');
});

function grpTenancyReport(User $user, CompanyGroup $group): array
{
    $access = GroupAccess::for($user->fresh());

    return app(GroupOverview::class)->build($access, $access->group($group->id));
}

it('answers 404 for another user\'s group on the page, the CSV and every action', function () {
    $this->actingAs($this->bo);

    $this->get(route('group.overview', ['koncern' => $this->annGroup->id]))->assertNotFound();
    $this->get(route('group.csv', $this->annGroup->id))->assertNotFound();

    $mark = CompanyGroupMark::query()->sole();
    $manage = app(ManageCompanyGroup::class);

    // The group id lives in state the browser can tamper with; it is resolved among the user's own, on render and in every action.
    // Another user's group and one that does not exist behave identically, so ids reveal nothing.
    $missing = (int) CompanyGroup::query()->max('id') + 1000;

    foreach ([$this->annGroup->id, $missing] as $id) {
        Livewire::test(GroupOverviewPage::class)->set('group', (string) $id)
            ->assertOk()
            ->assertSet('group', (string) $this->boGroup->id)
            ->assertDispatched('toast', type: 'error', message: 'Koncernen finns inte längre. Den kan ha tagits bort i ett annat fönster.')
            ->assertDontSee('Annas Ett AB');
        $this->get(route('group.overview', ['koncern' => $id]))->assertNotFound();
        $this->get(route('group.csv', $id))->assertNotFound();
        expect(fn () => $manage->rename($this->bo, $id, 'X'))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $manage->mark($this->bo, $id, $this->s->id, 3001, 'SALES'))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $manage->delete($this->bo, $id))->toThrow(ModelNotFoundException::class);
    }

    expect(fn () => $manage->rename($this->bo, $this->annGroup->id, 'Övertagen'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $manage->delete($this->bo, $this->annGroup->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $manage->suggest($this->bo, $this->annGroup->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $manage->unmark($this->bo, $this->annGroup->id, $mark->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $manage->mark($this->bo, $this->annGroup->id, $this->s->id, 3001, 'SALES'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $manage->setShare($this->bo, $this->annGroup->id, $this->s->id, '50'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $manage->removeCompany($this->bo, $this->annGroup->id, $this->s->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $manage->addCompany($this->bo, $this->annGroup->id, $this->b1->id))->toThrow(ModelNotFoundException::class)
        ->and(CompanyGroup::query()->whereKey($this->annGroup->id)->value('name'))->toBe('Annas koncern')
        ->and(CompanyGroupMark::query()->count())->toBe(1)
        ->and($this->annGroup->members()->count())->toBe(3);
});

it('lists only the user\'s own groups', function () {
    $this->actingAs($this->bo)->get(route('group.overview'))->assertOk()
        ->assertSee('Bos koncern')
        ->assertDontSee('Annas koncern')
        ->assertDontSee('Annas Ett AB');
});

it('removes a company from the view at once when the membership is removed, with its figures and marks', function () {
    expect(array_column(grpTenancyReport($this->ann, $this->annGroup)['companies'], 'name'))->toContain('Hemligt Bolag AB');

    $this->s->users()->detach($this->ann);
    $report = grpTenancyReport($this->ann, $this->annGroup);

    expect(array_column($report['companies'], 'name'))->toBe(['Annas Ett AB', 'Annas Två AB'])
        ->and($report['hidden'])->toBe(1)
        ->and($report['marks'])->toBe([])
        ->and($report['income']['result']['sum'])->toBe(2 * 7_777_700);

    $page = $this->actingAs($this->ann->fresh());

    $page->get(route('group.overview'))->assertOk()->assertDontSee('Hemligt Bolag AB')->assertSee('Ett företag i koncernen visas inte');

    $csv = $page->get(route('group.csv', $this->annGroup->id))->assertOk()->streamedContent();

    expect($csv)->not->toContain('Hemligt Bolag AB')->not->toContain('99999,00');

    // The settings tab lists neither the company nor its mark.
    Livewire::test(GroupOverviewPage::class)->call('show', 'installningar')->assertDontSee('Hemligt Bolag AB');
});

it('drops a company the user can no longer see reports of, but keeps the others', function () {
    $this->s->users()->updateExistingPivot($this->ann->id, ['role' => 'approver']);

    expect(array_column(grpTenancyReport($this->ann, $this->annGroup)['companies'], 'name'))->toBe(['Annas Ett AB', 'Annas Två AB']);

    // Bo, in the same company with the right role, still sees it in his own group.
    expect(array_column(grpTenancyReport($this->bo, $this->boGroup)['companies'], 'name'))->toBe(['Bos Bolag AB', 'Hemligt Bolag AB']);
});

it('never shows a company the user is not a member of, even if a row names it', function () {
    // A row put there by something other than the Action: the company is Bo's, not Ann's.
    DB::table('company_group_members')->insert(['company_group_id' => $this->annGroup->id, 'company_id' => $this->b1->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('company_group_marks')->insert(['company_group_id' => $this->annGroup->id, 'company_id' => $this->b1->id, 'account_number' => 3001, 'kind' => 'SALES', 'created_at' => now(), 'updated_at' => now()]);

    $report = grpTenancyReport($this->ann, $this->annGroup);

    expect(array_column($report['companies'], 'name'))->not->toContain('Bos Bolag AB')
        ->and($report['hidden'])->toBe(1)
        ->and(array_column($report['marks'], 'company'))->not->toContain('Bos Bolag AB');

    $this->actingAs($this->ann)->get(route('group.overview'))->assertOk()->assertDontSee('Bos Bolag AB');
    $this->get(route('group.csv', $this->annGroup->id))->assertOk();
    expect($this->get(route('group.csv', $this->annGroup->id))->streamedContent())->not->toContain('Bos Bolag AB');
});

it('refuses to add or mark a company the user is not a member of', function () {
    $manage = app(ManageCompanyGroup::class);

    expect(fn () => $manage->addCompany($this->ann, $this->annGroup->id, $this->b1->id))->toThrow(ValidationException::class)
        ->and(fn () => $manage->mark($this->ann, $this->annGroup->id, $this->b1->id, 3001, 'SALES'))->toThrow(ValidationException::class);

    // Posting as a visible company never reaches another company's chart of accounts: 1660 exists in none.
    expect($this->annGroup->members()->count())->toBe(3);
});

it('keeps the figures of two groups that share a company apart', function () {
    $ann = grpTenancyReport($this->ann, $this->annGroup);
    $bo = grpTenancyReport($this->bo, $this->boGroup);

    expect($ann['income']['result']['sum'])->toBe(2 * 7_777_700 + 9_999_900)
        ->and($bo['income']['result']['sum'])->toBe(7_777_700 + 9_999_900)
        // The mark Ann made in the shared company is not Bo's.
        ->and($ann['marks'])->toHaveCount(1)
        ->and($bo['marks'])->toBe([]);
});

it('gives a signed-out visitor no group', function () {
    $this->get(route('group.overview'))->assertRedirect();
    $this->get(route('group.csv', $this->annGroup->id))->assertRedirect();
});

it('does not touch the current company of the session', function () {
    $this->ann->switchCompany($this->a1);
    $this->actingAs($this->ann->fresh());
    grpTenancyReport($this->ann, $this->annGroup);

    expect(CurrentCompany::id())->toBe($this->a1->id);
});
