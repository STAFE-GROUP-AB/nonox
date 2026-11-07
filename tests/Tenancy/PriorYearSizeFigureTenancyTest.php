<?php

declare(strict_types=1);

use App\Actions\AnnualReport\SavePriorYearSizeFigures;
use App\Domain\AnnualReport\K3CompanySize;
use App\Models\FiscalYear;
use App\Models\PriorYearSizeFigure;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for prior-year size figures (ADR-059, addendum
 * 2026-11-07, A.2.5): company A never sees B's figures, through Eloquent,
 * the size test or the action; B's id answers 404.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));

    foreach (['a' => '1 000', 'b' => '99 000 000'] as $side => $turnover) {
        [$owner, $company] = Bookkeeping::company();
        $this->actingAs($owner);
        CurrentCompany::set($company);
        $figures = app(SavePriorYearSizeFigures::class)($owner, $company, [
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'average_employees_fte' => '1',
            'balance_sheet_total' => $turnover, 'net_turnover' => $turnover,
        ]);
        $this->{$side} = compact('owner', 'company', 'figures');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s prior-year figures through Eloquent or the size test', function () {
    expect(PriorYearSizeFigure::query()->pluck('id')->all())->toBe([$this->a['figures']->id])
        ->and(PriorYearSizeFigure::query()->whereKey($this->b['figures']->id)->exists())->toBeFalse();

    $year = FiscalYear::query()->whereYear('start_date', 2026)->firstOrFail();
    $years = K3CompanySize::priorYears($year);

    expect($years)->toHaveCount(1)
        ->and($years[0]->netTurnoverOre)->toBe(100_000);
});

it('answers 404 for B\'s figures in the action', function () {
    app(SavePriorYearSizeFigures::class)($this->a['owner'], $this->a['company'], [
        'id' => $this->b['figures']->id,
        'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'average_employees_fte' => '1',
        'balance_sheet_total' => '1', 'net_turnover' => '1',
    ]);
})->throws(ModelNotFoundException::class);

it('refuses a user who is not a member of the company', function () {
    app(SavePriorYearSizeFigures::class)($this->a['owner'], $this->b['company'], [
        'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'average_employees_fte' => '1',
        'balance_sheet_total' => '1', 'net_turnover' => '1',
    ]);
})->throws(AuthorizationException::class);

it('reads only the current company\'s years when the size test runs for B', function () {
    CurrentCompany::set($this->b['company']);
    $this->actingAs($this->b['owner']);

    $year = FiscalYear::query()->whereYear('start_date', 2026)->firstOrFail();

    expect(K3CompanySize::priorYears($year)[0]->netTurnoverOre)->toBe(9_900_000_000);
});
