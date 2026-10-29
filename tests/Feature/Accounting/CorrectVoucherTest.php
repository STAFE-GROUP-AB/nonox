<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->original = Bookkeeping::post($this->owner, $this->company, 50000, 1930, 3001);
});

function rowsOf($voucher): array
{
    return $voucher->rows()->with('account')->get()->map(fn (VoucherRow $row) => [
        $row->account->number, $row->debit_ore->ore, $row->credit_ore->ore,
    ])->all();
}

it('reverses the original in a new posted voucher linked both ways', function () {
    $correction = app(CorrectVoucher::class)($this->owner, $this->original, 'Fel konto', '2026-03-05');

    expect($correction->isPosted())->toBeTrue()
        ->and($correction->source)->toBe(VoucherSource::Correction)
        ->and($correction->reference())->toBe('A2')
        ->and($correction->corrects_voucher_id)->toBe($this->original->id)
        ->and($this->original->fresh()->corrected_by_voucher_id)->toBe($correction->id)
        ->and($correction->description)->toBe('Rättelse av A1: Fel konto')
        ->and(rowsOf($correction))->toBe([[1930, 0, 50000], [3001, 50000, 0]]);
});

it('can re-book the amount on the right account in the same correction', function () {
    $correction = app(CorrectVoucher::class)($this->owner, $this->original, 'Skulle vara 3002', null, [
        ['account' => 1930, 'debit_ore' => 50000],
        ['account' => 3002, 'credit_ore' => 50000],
    ]);

    expect(rowsOf($correction))->toBe([
        [1930, 0, 50000], [3001, 50000, 0], [1930, 50000, 0], [3002, 0, 50000],
    ]);
});

it('requires a reason', function () {
    app(CorrectVoucher::class)($this->owner, $this->original, '  ');
})->throws(ValidationException::class, 'Skriv varför verifikationen rättas.');

it('corrects a voucher only once', function () {
    app(CorrectVoucher::class)($this->owner, $this->original, 'Fel');

    expect(fn () => app(CorrectVoucher::class)($this->owner, $this->original->fresh(), 'Igen'))
        ->toThrow(AuthorizationException::class);
});

it('does not correct drafts', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company);

    expect(fn () => app(CorrectVoucher::class)($this->owner, $draft, 'Fel'))->toThrow(AuthorizationException::class);
});

it('leaves the original untouched apart from the link', function () {
    $before = rowsOf($this->original);
    $hash = $this->original->hash;

    app(CorrectVoucher::class)($this->owner, $this->original, 'Fel');

    expect(rowsOf($this->original->fresh()))->toBe($before)
        ->and($this->original->fresh()->hash)->toBe($hash);
});

it('records the correction in the audit log', function () {
    app(CorrectVoucher::class)($this->owner, $this->original, 'Fel konto');

    expect(AuditLog::forCompany($this->company)->where('action', 'voucher.corrected')->sole()->payload)
        ->toMatchArray(['reference' => 'A1', 'corrected_by' => 'A2', 'reason' => 'Fel konto']);
});

it('does not let an auditor correct', function () {
    $auditor = User::factory()->create();
    $this->company->users()->attach($auditor, ['role' => 'auditor']);

    expect(fn () => app(CorrectVoucher::class)($auditor->fresh(), $this->original, 'Fel'))->toThrow(AuthorizationException::class);
});
