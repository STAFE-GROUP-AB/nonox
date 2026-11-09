<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
use App\Console\Commands\VerifyLedgerCommand;
use App\Domain\Accounting\LedgerVerifier;
use App\Models\AuditLog;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    $this->a1 = Bookkeeping::post($this->owner, $this->company, 10000);
    $this->a2 = Bookkeeping::post($this->owner, $this->company, 20000);
    $this->b1 = Bookkeeping::post($this->owner, $this->company, 30000, series: 'B');
    app(CorrectVoucher::class)($this->owner, $this->a1, 'Fel');
});

/**
 * Simulates tampering directly in the database. The protection triggers are
 * disabled inside the test transaction, which rolls the change back afterwards.
 */
function tamper(string $sql, array $bindings = []): void
{
    DB::statement('ALTER TABLE vouchers DISABLE TRIGGER USER');
    DB::statement('ALTER TABLE voucher_rows DISABLE TRIGGER USER');
    DB::statement($sql, $bindings);
    DB::statement('ALTER TABLE vouchers ENABLE TRIGGER USER');
    DB::statement('ALTER TABLE voucher_rows ENABLE TRIGGER USER');
}

it('confirms an intact ledger', function () {
    expect(app(LedgerVerifier::class)->verify($this->company))->toBe(['ok' => true, 'vouchers' => 4, 'problems' => []]);
});

it('detects a changed voucher', function () {
    tamper('update vouchers set description = ? where id = ?', ['Ändrad i efterhand', $this->a2->id]);

    $result = app(LedgerVerifier::class)->verify($this->company);

    expect($result['ok'])->toBeFalse()
        ->and($result['problems'])->toContain('Räkenskapsår 2026: verifikation A2 har ändrats efter bokföringen.');
});

it('detects a changed amount', function () {
    tamper('update voucher_rows set debit_ore = debit_ore + 1, credit_ore = credit_ore where voucher_id = ? and debit_ore > 0', [$this->b1->id]);

    $result = app(LedgerVerifier::class)->verify($this->company);

    expect($result['problems'])->toContain('Räkenskapsår 2026: verifikation B1 har ändrats efter bokföringen.')
        ->and(collect($result['problems'])->contains(fn ($problem) => str_contains($problem, 'B1 balanserar inte')))->toBeTrue();
});

it('detects a removed voucher', function () {
    tamper('delete from voucher_rows where voucher_id = ?', [$this->b1->id]);
    tamper('delete from vouchers where id = ?', [$this->b1->id]);

    $result = app(LedgerVerifier::class)->verify($this->company);

    expect($result['ok'])->toBeFalse()
        ->and(collect($result['problems'])->contains(fn ($problem) => str_contains($problem, 'kedjan')))->toBeTrue()
        ->and(collect($result['problems'])->contains(fn ($problem) => str_contains($problem, 'serie B har luckor')))->toBeFalse();
});

it('detects a gap in the numbering', function () {
    tamper('update vouchers set number = 7 where id = ?', [$this->a2->id]);

    $result = app(LedgerVerifier::class)->verify($this->company);

    expect($result['problems'])->toContain('Räkenskapsår 2026: serie A har luckor eller dubbletter i numreringen.');
});

it('reports through the command and records the result', function () {
    Log::spy();

    $this->artisan('ledger:verify', ['company' => $this->company->org_number])
        ->expectsOutputToContain('OK: 4 bokförda verifikationer, inga avvikelser.')
        ->assertSuccessful();

    tamper('update vouchers set description = ? where id = ?', ['Ändrad', $this->a2->id]);

    $this->artisan('ledger:verify', ['--all' => true])
        ->expectsOutputToContain('verifikation A2 har ändrats efter bokföringen')
        ->assertExitCode(VerifyLedgerCommand::EXIT_DEVIATIONS);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'avvikelser') && $context === ['companies' => [$this->company->id]]);

    expect(AuditLog::forCompany($this->company)->where('action', 'ledger.verified')->orderBy('id')->pluck('payload')->map(fn ($p) => $p['ok'])->all())
        ->toBe([true, false]);
});

it('is scheduled every night', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains($event->command, 'ledger:verify --all') && $event->expression === '15 3 * * *'))->toBeTrue();
});
