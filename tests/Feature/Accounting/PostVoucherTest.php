<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Accounting\UpdateAccount;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\LedgerHash;
use App\Domain\Accounting\LedgerVerifier;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
});

it('posts a balanced draft with the first number in the series', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company);

    expect($voucher->status)->toBe(VoucherStatus::Posted)
        ->and($voucher->number)->toBe(1)
        ->and($voucher->reference())->toBe('A1')
        ->and($voucher->posted_by)->toBe($this->owner->id)
        ->and($voucher->posted_at)->not->toBeNull()
        ->and($voucher->previous_hash)->toBeNull()
        ->and($voucher->hash)->toHaveLength(64);
});

it('numbers each series without gaps and separately', function () {
    $a1 = Bookkeeping::post($this->owner, $this->company);
    $a2 = Bookkeeping::post($this->owner, $this->company);
    $b1 = Bookkeeping::post($this->owner, $this->company, series: 'B');
    $a3 = Bookkeeping::post($this->owner, $this->company);

    expect([$a1->reference(), $a2->reference(), $b1->reference(), $a3->reference()])->toBe(['A1', 'A2', 'B1', 'A3']);
});

it('starts numbering again in a new fiscal year', function () {
    app(CreateNextFiscalYear::class)($this->owner, $this->company);

    Bookkeeping::post($this->owner, $this->company, date: '2026-12-31');
    $next = Bookkeeping::post($this->owner, $this->company, date: '2027-01-02');

    expect($next->reference())->toBe('A1');
});

it('does not use up a number when posting fails', function () {
    $unbalanced = app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-03-01', 'description' => 'Fel', 'rows' => [
            ['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 99],
        ],
    ]);

    expect(fn () => app(PostVoucher::class)($this->owner, $unbalanced))->toThrow(ValidationException::class);

    expect(Bookkeeping::post($this->owner, $this->company)->number)->toBe(1);
});

it('chains every posted voucher to the previous one', function () {
    $first = Bookkeeping::post($this->owner, $this->company);
    $second = Bookkeeping::post($this->owner, $this->company, series: 'B');

    $fiscalYear = FiscalYear::query()->sole();

    expect($second->previous_hash)->toBe($first->hash)
        ->and($fiscalYear->ledger_head_hash)->toBe($second->hash)
        ->and(LedgerHash::of($second->fresh()->load(['rows.account', 'series']), $first->hash))->toBe($second->hash);
});

it('records posting in the audit log', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, 123456);

    expect(AuditLog::forCompany($this->company)->where('action', 'voucher.posted')->sole()->payload)
        ->toMatchArray(['reference' => 'A1', 'amount_ore' => 123456, 'hash' => $voucher->hash]);
});

it('refuses to post when a rule is broken', function (callable $arrange, string $message) {
    $draft = $arrange($this->owner, $this->company);

    expect(fn () => app(PostVoucher::class)($this->owner, $draft))->toThrow(ValidationException::class, $message);
    expect($draft->fresh()->status)->toBe(VoucherStatus::Draft);
})->with([
    'one row' => [fn ($user, $company) => app(SaveDraftVoucher::class)($user, $company, [
        'voucher_date' => '2026-03-01', 'description' => 'En rad', 'rows' => [['account' => 1930, 'debit_ore' => 100]],
    ]), 'minst två rader'],
    'unbalanced' => [fn ($user, $company) => app(SaveDraftVoucher::class)($user, $company, [
        'voucher_date' => '2026-03-01', 'description' => 'Obalans', 'rows' => [
            ['account' => 1930, 'debit_ore' => 1000], ['account' => 3001, 'credit_ore' => 900],
        ],
    ]), 'Skillnaden är 100 öre'],
    'locked period' => [function ($user, $company) {
        $company->forceFill(['locked_until_date' => '2026-03-31'])->save();

        return Bookkeeping::draft($user, $company, date: '2026-03-15');
    }, 'Perioden till och med 2026-03-31 är låst'],
    'closed fiscal year' => [function ($user, $company) {
        $draft = Bookkeeping::draft($user, $company);
        FiscalYear::query()->update(['status' => FiscalYearStatus::Closed->value]);

        return $draft;
    }, 'är stängt'],
    'inactive account' => [function ($user, $company) {
        $draft = Bookkeeping::draft($user, $company);
        app(UpdateAccount::class)($user, Account::query()->where('number', 3001)->sole(), ['is_active' => false]);

        return $draft;
    }, 'kontot är inaktiverat'],
    'required cost centre' => [function ($user, $company) {
        Account::query()->where('number', 3001)->update(['requires_cost_center' => true]);

        return Bookkeeping::draft($user, $company);
    }, 'kräver ett kostnadsställe'],
]);

it('refuses a date outside every fiscal year', function () {
    expect(fn () => Bookkeeping::draft($this->owner, $this->company, date: '2030-01-01'))
        ->toThrow(ValidationException::class, 'Det finns inget räkenskapsår som omfattar 2030-01-01');
});

it('validates the rows of a draft', function (array $row, string $message) {
    expect(fn () => app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-03-01', 'description' => 'Rad', 'rows' => [$row],
    ]))->toThrow(ValidationException::class, $message);
})->with([
    'unknown account' => [['account' => 9998, 'debit_ore' => 100], 'kontot finns inte'],
    'both sides' => [['account' => 1930, 'debit_ore' => 100, 'credit_ore' => 100], 'inte båda'],
    'no amount' => [['account' => 1930], 'skriv ett belopp'],
    'negative' => [['account' => 1930, 'debit_ore' => -100], 'kan inte vara negativt'],
    'float amount' => [['account' => 1930, 'debit_ore' => 12.5], 'hela ören'],
]);

it('only lets members with permission post', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company);
    $auditor = User::factory()->create();
    $this->company->users()->attach($auditor, ['role' => 'auditor']);

    expect(fn () => app(PostVoucher::class)($auditor->fresh(), $draft))->toThrow(AuthorizationException::class);
});

it('always posts random balanced vouchers and never unbalanced ones', function () {
    $accounts = [1930, 1510, 2440, 3001, 4010, 5010, 6570, 6110];
    mt_srand(20261029);

    foreach (range(1, 25) as $attempt) {
        $rows = [];
        $total = 0;

        foreach (range(1, mt_rand(1, 4)) as $line) {
            $ore = mt_rand(1, 10_000_000);
            $total += $ore;
            $rows[] = ['account' => $accounts[array_rand($accounts)], 'debit_ore' => $ore];
        }

        $offset = $attempt % 2 === 0 ? 0 : mt_rand(1, 1000);
        $rows[] = ['account' => $accounts[array_rand($accounts)], 'credit_ore' => $total + $offset];

        $draft = app(SaveDraftVoucher::class)($this->owner, $this->company, [
            'voucher_date' => '2026-05-01', 'description' => "Slump {$attempt}", 'rows' => $rows,
        ]);

        if ($offset === 0) {
            expect(app(PostVoucher::class)($this->owner, $draft)->isPosted())->toBeTrue();
        } else {
            expect(fn () => app(PostVoucher::class)($this->owner, $draft))->toThrow(ValidationException::class);
        }
    }

    expect(app(LedgerVerifier::class)->verify($this->company)['ok'])->toBeTrue();
});
