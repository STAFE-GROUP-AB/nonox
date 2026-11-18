<?php

declare(strict_types=1);

/*
 * ADR-081 gap 6, package E6: the project register (customer, project
 * manager, status, dates, price form, contract sum, estimated cost, default
 * cost centre), its permissions and the default dimensions on articles and
 * customers.
 */

use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveProjectDetails;
use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Accounting\Dimension;
use App\Domain\Projects\ProjectPriceForm;
use App\Domain\Projects\ProjectStatus;
use App\Livewire\Accounting\Dimensions;
use App\Livewire\Invoicing\Articles;
use App\Livewire\Invoicing\Customers;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::CostCenter, ['code' => '10', 'name' => 'Verkstad']);
    $this->project = app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::Project, ['code' => 'P1', 'name' => 'Ombyggnad kök']);
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Beställaren AB', 'payment_terms_days' => 30]);
});

/** @return array<string, string> */
function e6Details(array $extra = []): array
{
    return [
        'customer_id' => (string) test()->customer->id,
        'manager_user_id' => (string) test()->owner->id,
        'status' => 'ACTIVE',
        'starts_on' => '2026-04-01',
        'ends_on' => '2026-09-30',
        'price_form' => 'FIXED',
        'contract_sum' => '250 000',
        'estimated_cost' => '180 000,50',
        'default_cost_center' => '10',
        ...$extra,
    ];
}

it('keeps the register details of a project, with an audit entry of ids and amounts only', function () {
    $project = app(SaveProjectDetails::class)($this->owner, $this->company, $this->project, e6Details())->fresh();

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->price_form)->toBe(ProjectPriceForm::Fixed)
        ->and($project->customer_id)->toBe($this->customer->id)
        ->and($project->manager_user_id)->toBe($this->owner->id)
        ->and($project->starts_on->toDateString())->toBe('2026-04-01')
        ->and($project->contract_sum_ore->ore)->toBe(25_000_000)
        ->and($project->estimated_cost_ore->ore)->toBe(18_000_050)
        ->and($project->defaultCostCenter->code)->toBe('10')
        ->and($project->code)->toBe('P1');

    $entry = AuditLog::query()->where('action', 'project.updated')->sole();

    expect($entry->payload['after'])->toMatchArray(['customer_id' => $this->customer->id, 'price_form' => 'FIXED', 'contract_sum_ore' => 25_000_000])
        ->and(json_encode($entry->payload))->not->toContain('Beställaren')
        ->and(json_encode($entry->payload))->not->toContain($this->owner->name);
});

it('makes existing projects active, and new ones too', function () {
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and(DB::table('projects')->where('id', $this->project->id)->value('status'))->toBe('ACTIVE');
});

it('validates the dates, the amounts and the project manager', function (array $input, string $field) {
    expect(fn () => app(SaveProjectDetails::class)($this->owner, $this->company, $this->project, e6Details($input)))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));
})->with([
    'end before start' => [['ends_on' => '2026-03-01'], 'ends_on'],
    'unreadable sum' => [['contract_sum' => 'mycket'], 'contract_sum'],
    'negative cost' => [['estimated_cost' => '-5'], 'estimated_cost'],
    'unknown status' => [['status' => 'PAUSED'], 'status'],
    'unknown cost centre' => [['default_cost_center' => '99'], 'default_cost_center'],
    'not a member' => [fn () => ['manager_user_id' => (string) User::factory()->create()->id], 'manager_user_id'],
]);

it('refuses another company\'s customer and cost centre', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $foreign = app(SaveCustomer::class)($otherOwner, $other, ['name' => 'Främmande AB', 'payment_terms_days' => 30]);
    app(SaveDimensionObject::class)($otherOwner, $other, Dimension::CostCenter, ['code' => 'X1', 'name' => 'Främmande']);
    CurrentCompany::set($this->company);

    expect(fn () => app(SaveProjectDetails::class)($this->owner, $this->company, $this->project, e6Details(['customer_id' => (string) $foreign->id])))
        ->toThrow(ValidationException::class, 'Kunden finns inte.')
        ->and(fn () => app(SaveProjectDetails::class)($this->owner, $this->company, $this->project, e6Details(['default_cost_center' => 'X1'])))
        ->toThrow(ValidationException::class, 'Kostnadsstället X1 finns inte.');
});

it('lets those who manage projects or accounts keep the register, and the others only see it', function (string $role, bool $sees, bool $manages) {
    $member = Bookkeeping::member($this->company, $role);

    expect($member->can('viewAny', [Project::class, $this->company]))->toBe($sees)
        ->and($member->can('update', $this->project))->toBe($manages)
        ->and($member->hasTeamPermission($this->company, 'projects:manage'))->toBe($manages);

    if (! $manages) {
        expect(fn () => app(SaveProjectDetails::class)($member, $this->company, $this->project, e6Details()))->toThrow(AuthorizationException::class);
    }
})->with([
    'administratör' => ['admin', true, true],
    'redovisningskonsult' => ['accountant', true, true],
    'bokförare' => ['bookkeeper', true, false],
    'attestant' => ['approver', true, false],
    'revisor' => ['auditor', true, false],
]);

it('edits the details on the project page with a toast, and hides a manager who has left', function () {
    $manager = Bookkeeping::member($this->company, 'bookkeeper');
    CurrentCompany::set($this->company);

    Livewire::test(Dimensions::class, ['dimension' => 'projekt'])
        ->call('startDetails', $this->project->id)
        ->assertSee('Projektledare')
        ->assertSee('Beställaren AB')
        ->set('details.manager_user_id', (string) $manager->id)
        ->set('details.price_form', 'TIME_AND_MATERIAL')
        ->set('details.contract_sum', '')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Uppgifterna för projekt P1 Ombyggnad kök är sparade.')
        ->assertSee('Löpande räkning')
        ->assertSee("Projektledare: {$manager->name}");

    $this->company->users()->detach($manager);

    Livewire::test(Dimensions::class, ['dimension' => 'projekt'])->assertDontSee("Projektledare: {$manager->name}");
});

it('answers 404 for another company\'s project on the page', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $foreign = app(SaveDimensionObject::class)($otherOwner, $other, Dimension::Project, ['code' => 'Q1', 'name' => 'Hemligt']);
    CurrentCompany::set($this->company);

    Livewire::test(Dimensions::class, ['dimension' => 'projekt'])->call('startDetails', $foreign->id)->assertNotFound();

    expect(Project::query()->pluck('code')->all())->toBe(['P1']);
});

it('lists the project register in a fixed number of queries', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Dimensions::class, ['dimension' => 'projekt']);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    app(SaveProjectDetails::class)($this->owner, $this->company, $this->project, e6Details());
    $count();
    $short = $count();

    foreach (range(2, 12) as $n) {
        $project = app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::Project, ['code' => "P{$n}", 'name' => "Projekt {$n}"]);
        app(SaveProjectDetails::class)($this->owner, $this->company, $project, e6Details());
    }

    expect($count())->toBe($short);
});

it('saves the default cost centre and project on an article and a customer', function () {
    Livewire::test(Articles::class)
        ->call('create')
        ->assertSee('Projekt på nya rader')
        ->set('form.number', 'MONT')
        ->set('form.name', 'Montage')
        ->set('form.price', '650')
        ->set('form.default_cost_center', '10')
        ->set('form.default_project', 'P1')
        ->call('save')
        ->assertHasNoErrors();

    $article = Article::query()->where('number', 'MONT')->with(['defaultCostCenter', 'defaultProject'])->sole();

    expect($article->defaultCostCenter->code)->toBe('10')->and($article->defaultProject->code)->toBe('P1');

    Livewire::test(Customers::class)
        ->call('edit', $this->customer->id)
        ->assertSet('form.default_project', '')
        ->set('form.default_project', 'P1')
        ->call('save')
        ->assertHasNoErrors();

    expect(Customer::query()->find($this->customer->id)->default_project_id)->toBe($this->project->id);
});
