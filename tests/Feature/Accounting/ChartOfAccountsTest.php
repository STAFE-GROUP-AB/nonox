<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateAccount;
use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Accounting\SeedChartOfAccounts;
use App\Actions\Accounting\UpdateAccount;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\ChartOfAccounts;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->withCompany()->create();
    $this->company = $this->owner->currentCompany();
    app(InitialiseBookkeeping::class)($this->company);
    $this->actingAs($this->owner = $this->owner->fresh());
});

function memberOf(Company $company, string $role): User
{
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => $role]);
    $user->switchCompany($company);

    return $user->fresh();
}

it('shows the chart of accounts on /kontoplan', function () {
    $this->get(route('accounting.accounts'))
        ->assertOk()
        ->assertSee('Kontoplan')
        ->assertSee('Företagskonto/checkkonto/affärskonto');

    expect(route('accounting.accounts', absolute: false))->toBe('/kontoplan');
});

it('adds an account and derives its type', function () {
    $account = app(CreateAccount::class)($this->owner, $this->company, ['number' => '6993', 'name' => ' Lämnade bidrag ']);

    expect($account->number)->toBe(6993)
        ->and($account->name)->toBe('Lämnade bidrag')
        ->and($account->type->value)->toBe('EXPENSE')
        ->and($account->company_id)->toBe($this->company->id)
        ->and(AuditLog::forCompany($this->company)->where('action', 'account.created')->sole()->payload['number'])->toBe(6993);
});

it('refuses duplicate and out-of-range account numbers', function (string $number, string $message) {
    expect(fn () => app(CreateAccount::class)($this->owner, $this->company, ['number' => $number, 'name' => 'Test']))
        ->toThrow(ValidationException::class, $message);
})->with([
    'duplicate' => ['1930', 'Konto 1930 finns redan i kontoplanen.'],
    'too small' => ['999', 'mellan 1000 och 9999'],
    'too large' => ['10000', 'mellan 1000 och 9999'],
]);

it('allows the same account number in another company', function () {
    $other = Company::factory()->create();
    app(InitialiseBookkeeping::class)($other);

    $account = app(CreateAccount::class)($this->owner, $this->company, ['number' => '6993', 'name' => 'Bidrag']);

    expect(CurrentCompany::run($other, fn () => Account::query()->where('number', 6993)->exists()))->toBeFalse()
        ->and($account->exists)->toBeTrue();
});

it('renames and deactivates an account, and records it', function () {
    $account = Account::query()->where('number', 6570)->sole();

    app(UpdateAccount::class)($this->owner, $account, ['name' => 'Bankavgifter']);
    app(UpdateAccount::class)($this->owner, $account->fresh(), ['is_active' => false]);

    expect($account->fresh())->name->toBe('Bankavgifter')->is_active->toBeFalse()->number->toBe(6570);

    $changes = AuditLog::forCompany($this->company)->where('action', 'account.updated')->orderBy('id')->pluck('payload');

    expect($changes[0])->toEqual(['number' => 6570, 'before' => ['name' => 'Bankkostnader'], 'after' => ['name' => 'Bankavgifter']])
        ->and($changes[1])->toEqual(['number' => 6570, 'before' => ['is_active' => true], 'after' => ['is_active' => false]]);
});

it('does not let a bookkeeper change the chart of accounts', function () {
    $bookkeeper = memberOf($this->company, 'bookkeeper');

    expect(fn () => app(CreateAccount::class)($bookkeeper, $this->company->fresh(), ['number' => '6993', 'name' => 'X']))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($bookkeeper);
    Livewire::test(ChartOfAccounts::class)->assertDontSee('Lägg till ett konto')->assertSee('1930');
});

it('adds an account from the page with a toast', function () {
    Livewire::test(ChartOfAccounts::class)
        ->set('newNumber', '6993')
        ->set('newName', 'Lämnade bidrag')
        ->call('addAccount')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Konto 6993 Lämnade bidrag är tillagt.')
        ->assertSet('newNumber', '');
});

it('shows a Swedish error for a duplicate number on the page', function () {
    Livewire::test(ChartOfAccounts::class)
        ->set('newNumber', '1930')
        ->set('newName', 'Dubblett')
        ->call('addAccount')
        ->assertHasErrors('number')
        ->assertSee('Konto 1930 finns redan i kontoplanen.');
});

it('renames and toggles accounts from the page with toasts', function () {
    $account = Account::query()->where('number', 6570)->sole();

    Livewire::test(ChartOfAccounts::class)
        ->call('startEditing', $account->id)
        ->set('editingName', 'Bankavgifter')
        ->call('saveName')
        ->assertDispatched('toast', message: 'Konto 6570 heter nu Bankavgifter.')
        ->call('toggleActive', $account->id)
        ->assertDispatched('toast', message: 'Konto 6570 är inaktiverat. Det syns inte längre när du bokför.')
        ->call('toggleActive', $account->id)
        ->assertDispatched('toast', message: 'Konto 6570 är aktiverat igen.');
});

it('searches by number and name and filters by class', function () {
    Livewire::test(ChartOfAccounts::class)
        ->set('search', '193')
        ->assertSee('Företagskonto/checkkonto/affärskonto')
        ->assertDontSee('Kundfordringar')
        ->set('search', 'kundford')
        ->assertSee('Kundfordringar')
        ->set('search', '')
        ->set('class', 3)
        ->assertSee('Försäljning inom Sverige, 25 % moms')
        ->assertDontSee('Kundfordringar');
});

it('hides inactive accounts unless asked', function () {
    app(UpdateAccount::class)($this->owner, Account::query()->where('number', 6570)->sole(), ['is_active' => false]);

    Livewire::test(ChartOfAccounts::class)
        ->set('search', '6570')
        ->assertSee('Inga konton hittades')
        ->set('showInactive', true)
        ->assertSee('Bankkostnader')
        ->assertSee('Inaktivt');
});

it('cannot touch another company\'s accounts', function () {
    $other = Company::factory()->create();
    app(InitialiseBookkeeping::class)($other);
    $foreign = CurrentCompany::run($other, fn () => Account::query()->where('number', 1930)->sole());

    Livewire::test(ChartOfAccounts::class)
        ->call('toggleActive', $foreign->id)
        ->assertNotFound();

    expect(CurrentCompany::run($other, fn () => $foreign->fresh()->is_active))->toBeTrue();
});

it('gives a partnership or sole trader owner equity accounts, never Aktiekapital, whatever the framework', function () {
    $seed = app(SeedChartOfAccounts::class);
    $equity = fn (array $accounts): array => array_values(array_map(fn (array $account): int => $account['number'], array_filter($accounts, fn (array $account): bool => $account['number'] >= 2000 && $account['number'] < 2100)));

    expect($equity($seed->template(ReportingFramework::K2, LegalForm::Hb)))->toBe([2010, 2013, 2018, 2019])
        ->and($equity($seed->template(ReportingFramework::K3, LegalForm::Kb)))->toBe([2010, 2013, 2018, 2019])
        ->and($equity($seed->template(ReportingFramework::K2, LegalForm::Ef)))->toBe([2010, 2013, 2018, 2019])
        ->and($equity($seed->template(ReportingFramework::K2, LegalForm::Ab)))->toContain(2081)->not->toContain(2010)
        ->and(count($seed->template(ReportingFramework::K2, LegalForm::Hb)))->toBe(count($seed->template(ReportingFramework::K2)) - 5 + 4);
});
