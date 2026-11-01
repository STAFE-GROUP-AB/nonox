<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\Dimension;
use App\Livewire\Accounting\Dimensions;
use App\Livewire\Accounting\VoucherEditor;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

/** @param  array<int, array<string, mixed>>  $rows */
function draftWithRows(array $rows): Voucher
{
    return app(SaveDraftVoucher::class)(test()->owner, test()->company, [
        'voucher_date' => '2026-06-01',
        'description' => 'Dagskassa',
        'rows' => $rows,
    ]);
}

it('keeps a register of cost centres and projects with toasts', function (Dimension $dimension, string $path) {
    expect(route($dimension->routeName(), absolute: false))->toBe($path);

    $this->get(route($dimension->routeName()))->assertOk()->assertSee($dimension->pluralLabel());

    $component = Livewire::test(Dimensions::class, ['dimension' => $dimension->value])
        ->set('newCode', '10')
        ->set('newName', 'Restaurang Malmö')
        ->call('add')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: "{$dimension->label()} 10 Restaurang Malmö är tillagt.")
        ->assertSee('Restaurang Malmö');

    $object = $dimension->model()::query()->sole();

    $component->call('startEditing', $object->id)
        ->set('editingName', 'Restaurang Malmö C')
        ->call('saveName')
        ->assertDispatched('toast', message: 'Namnet är ändrat till Restaurang Malmö C.')
        ->call('toggleActive', $object->id)
        ->assertSee('Inaktivt');

    expect($object->fresh())->name->toBe('Restaurang Malmö C')->is_active->toBeFalse()
        ->and(AuditLog::forCompany($this->company)->where('action', 'dimension.updated')->count())->toBe(2);

    $component->set('newCode', '10')->set('newName', 'Igen')->call('add')->assertHasErrors(['code']);
})->with([
    'cost centres' => [Dimension::CostCenter, '/kostnadsstallen'],
    'projects' => [Dimension::Project, '/projekt'],
]);

it('never changes a code', function () {
    $object = app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::CostCenter, ['code' => '10', 'name' => 'Malmö']);

    expect(fn () => app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::CostCenter, ['code' => '11', 'name' => 'Malmö'], $object))
        ->toThrow(ValidationException::class);
});

it('lets only those who manage accounts change the registers', function (string $role, bool $allowed) {
    $this->actingAs(Bookkeeping::member($this->company, $role));

    $this->get(route('accounting.costCenters'))->assertOk();

    $attempt = fn () => app(SaveDimensionObject::class)(auth()->user(), $this->company, Dimension::CostCenter, ['code' => '10', 'name' => 'Malmö']);

    $allowed ? expect($attempt())->toBeInstanceOf(CostCenter::class) : expect($attempt)->toThrow(AuthorizationException::class);
})->with([
    'accountant' => ['accountant', true],
    'bookkeeper' => ['bookkeeper', false],
    'auditor' => ['auditor', false],
]);

it('puts cost centre and project on voucher rows by code', function () {
    CostCenter::create(['code' => '10', 'name' => 'Malmö']);
    Project::create(['code' => 'P1', 'name' => 'Kök']);

    $draft = draftWithRows([
        ['account' => 1930, 'debit_ore' => 1000, 'cost_center' => '10'],
        ['account' => 3001, 'credit_ore' => 1000, 'cost_center' => '10', 'project' => 'P1'],
    ]);

    expect($draft->load('rows.costCenter', 'rows.project')->rows->map(fn ($row) => [$row->costCenter?->code, $row->project?->code])->all())
        ->toBe([['10', null], ['10', 'P1']]);
});

it('refuses an unknown code, and another company\'s', function () {
    [, $other] = Bookkeeping::company();
    CurrentCompany::run($other, fn () => CostCenter::create(['code' => '99', 'name' => 'Främmande']));

    expect(fn () => draftWithRows([
        ['account' => 1930, 'debit_ore' => 1000, 'cost_center' => '99'],
        ['account' => 3001, 'credit_ore' => 1000],
    ]))->toThrow(ValidationException::class, 'Rad 1: kostnadsstället 99 finns inte.');
});

it('requires a cost centre when the account asks for one', function () {
    CostCenter::create(['code' => '10', 'name' => 'Malmö']);
    Account::query()->where('number', 3001)->update(['requires_cost_center' => true]);

    $without = draftWithRows([['account' => 1930, 'debit_ore' => 1000], ['account' => 3001, 'credit_ore' => 1000]]);
    expect(fn () => app(PostVoucher::class)($this->owner, $without))->toThrow(ValidationException::class, 'kräver ett kostnadsställe');

    $with = draftWithRows([['account' => 1930, 'debit_ore' => 1000], ['account' => 3001, 'credit_ore' => 1000, 'cost_center' => '10']]);
    expect(app(PostVoucher::class)($this->owner, $with)->isPosted())->toBeTrue();
});

it('carries cost centres and projects into a correction', function () {
    CostCenter::create(['code' => '10', 'name' => 'Malmö']);
    Project::create(['code' => 'P1', 'name' => 'Kök']);

    $voucher = app(PostVoucher::class)($this->owner, draftWithRows([
        ['account' => 1930, 'debit_ore' => 1000],
        ['account' => 3001, 'credit_ore' => 1000, 'cost_center' => '10', 'project' => 'P1'],
    ]));

    $correction = app(CorrectVoucher::class)($this->owner, $voucher, 'Fel belopp')->load('rows.costCenter', 'rows.project');

    expect($correction->rows[1]->costCenter->code)->toBe('10')
        ->and($correction->rows[1]->project->code)->toBe('P1')
        ->and($correction->rows[1]->debit_ore->ore)->toBe(1000);
});

describe('in the voucher editor', function () {
    it('hides the columns until there is something to choose', function () {
        Livewire::test(VoucherEditor::class)->assertDontSee('row-cost-center-')->assertDontSee('row-project-');

        CostCenter::create(['code' => '10', 'name' => 'Malmö']);

        Livewire::test(VoucherEditor::class)->assertSee('row-cost-center-')->assertDontSee('row-project-');
    });

    it('posts with the cost centre and project chosen in the list, and shows them', function () {
        CostCenter::create(['code' => '10', 'name' => 'Malmö']);
        Project::create(['code' => 'P1', 'name' => 'Kök']);

        Livewire::test(VoucherEditor::class)
            ->set('description', 'Dagskassa')
            ->set('rows', [
                ['account' => '1930', 'debit' => '100', 'credit' => '', 'description' => '', 'cost_center' => '', 'project' => ''],
                ['account' => '3001', 'debit' => '', 'credit' => '100', 'description' => '', 'cost_center' => '10 Malmö', 'project' => 'P1 Kök'],
            ])
            ->call('post')
            ->assertHasNoErrors();

        $voucher = Voucher::query()->with('rows.costCenter', 'rows.project')->sole();

        expect($voucher->rows[1]->costCenter->code)->toBe('10')
            ->and($voucher->rows[1]->project->code)->toBe('P1');

        $this->get(route('accounting.vouchers.show', $voucher))->assertOk()
            ->assertSeeInOrder(['Konto', 'Kostnadsställe', 'Projekt', 'Radtext', '3001', '10 Malmö', 'P1 Kök']);
    });

    it('explains an unknown code on the right row', function () {
        Livewire::test(VoucherEditor::class)
            ->set('description', 'Dagskassa')
            ->set('rows', [
                ['account' => '1930', 'debit' => '100', 'credit' => '', 'description' => '', 'cost_center' => '', 'project' => ''],
                ['account' => '3001', 'debit' => '', 'credit' => '100', 'description' => '', 'cost_center' => '77', 'project' => ''],
            ])
            ->call('saveDraft')
            ->assertHasErrors('rows.1.cost_center')
            ->assertSee('Rad 2: kostnadsstället 77 finns inte.');
    });
});

it('shows income, costs and result per project for the year', function () {
    app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::Project, ['code' => 'P1', 'name' => 'Villa Ek']);
    app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::Project, ['code' => 'P2', 'name' => 'Kök Lind']);
    app(PostVoucher::class)($this->owner, draftWithRows([['account' => 1930, 'debit_ore' => 100_000], ['account' => 3001, 'credit_ore' => 100_000, 'project' => 'P1']]));
    app(PostVoucher::class)($this->owner, draftWithRows([['account' => 5010, 'debit_ore' => 30_000, 'project' => 'P1'], ['account' => 1930, 'credit_ore' => 30_000]]));

    Livewire::test(Dimensions::class, ['dimension' => 'projekt'])
        ->assertSeeInOrder(['Villa Ek', "1\u{a0}000,00", '300,00', '700,00', 'Kök Lind']);
});
