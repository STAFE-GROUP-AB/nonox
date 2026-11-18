<?php

declare(strict_types=1);

use App\Actions\Analysis\DeleteReport;
use App\Actions\Analysis\PinReport;
use App\Actions\Analysis\SaveReport;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Privacy\DeleteAccount;
use App\Domain\Analysis\Export\ReportFiles;
use App\Domain\Analysis\Export\ReportTable;
use App\Domain\Analysis\Reports\ReportVisibility;
use App\Domain\Analysis\Reports\SavedReports;
use App\Livewire\Analysis\AnalysisPage;
use App\Models\ReportDefinition;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 E18, review fixes: no formula cells in XLSX, a non-sharer cannot
 * save as company, rolling default, the life of a report when its owner
 * leaves, who looks after an ownerless company report, the pinned query and
 * the list limit.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    $this->fixture = DimensionScopeFixture::make();
    $this->actingAs($this->fixture->owner);
    CurrentCompany::forget();
});

function e18bSave($test, $user, string $name, string $visibility = 'COMPANY'): ReportDefinition
{
    return app(SaveReport::class)($user, $test->fixture->company, [
        'dataset' => 'ledger', 'rows' => ['account'], 'measure' => 'net', 'from' => '2026-01', 'to' => '2026-12',
        'name' => $name, 'visibility' => $visibility, 'rolling' => false,
    ]);
}

it('writes no formula for a text that starts with =, in the XLSX sheet', function () {
    $table = new ReportTable('=HYPERLINK("http://x","y")', '=1+1', ['=1+1', 'Belopp'], [['=HYPERLINK("http://x","y")', 12_345], ['Summa', null]], true);
    $path = tempnam(sys_get_temp_dir(), 'e18x');
    file_put_contents($path, ReportFiles::xlsx($table));
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $strings = (string) $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    unlink($path);

    expect($sheet)->not->toContain('<f>')->not->toContain('<f ')
        ->and($sheet.$strings)->toContain('HYPERLINK')->toContain('=1+1');
});

it('saves as private when someone without analysis:share opens a company report and saves a new one', function () {
    $report = e18bSave($this, $this->fixture->owner, 'Delad');
    $bookkeeper = Bookkeeping::member($this->fixture->company, 'bookkeeper');
    CurrentCompany::forget();

    try {
        $page = Livewire::actingAs($bookkeeper)->withQueryParams(['rapport' => (string) $report->id])->test(AnalysisPage::class);
    } finally {
        Livewire::withQueryParams([]);
    }

    expect($page->get('reportVisibility'))->toBe('PRIVATE');

    $page->set('reportName', 'Min kopia')->call('saveReport')->assertDispatched('toast', message: 'Rapporten Min kopia är sparad.', type: 'success');
    $page->set('reportVisibility', 'COMPANY')->set('reportName', 'Kapad delning')->call('saveReport');

    expect(ReportDefinition::query()->where('name', 'Min kopia')->value('visibility'))->toBe(ReportVisibility::Private)
        ->and(ReportDefinition::query()->where('name', 'Kapad delning')->value('visibility'))->toBe(ReportVisibility::Private);
});

it('defaults to a rolling period only when the period ends in the current month', function () {
    expect(Livewire::test(AnalysisPage::class)->get('reportRolling'))->toBeTrue();

    $page = Livewire::test(AnalysisPage::class)->set('to', '2026-03');

    expect($page->get('reportRolling'))->toBeFalse();

    $page->set('to', '2026-06');

    expect($page->get('reportRolling'))->toBeTrue();
});

it('deletes a leaving member\'s private reports and keeps the company ones', function () {
    $member = Bookkeeping::member($this->fixture->company, 'accountant');
    $private = e18bSave($this, $member, 'Hens privata', 'PRIVATE');
    $shared = e18bSave($this, $member, 'Hens delade');
    $ownerPrivate = e18bSave($this, $this->fixture->owner, 'Ägarens', 'PRIVATE');

    app(RemoveTeamMember::class)->remove($this->fixture->owner, $this->fixture->company, $member);

    expect(ReportDefinition::query()->whereKey($private->id)->exists())->toBeFalse()
        ->and(ReportDefinition::query()->whereKey($shared->id)->exists())->toBeTrue()
        ->and(ReportDefinition::query()->findOrFail($shared->id)->owner_user_id)->toBeNull()
        ->and(ReportDefinition::query()->whereKey($ownerPrivate->id)->exists())->toBeTrue();
});

it('deletes the private reports in every company on account deletion and leaves company reports ownerless', function () {
    $member = Bookkeeping::member($this->fixture->company, 'accountant');
    $private = e18bSave($this, $member, 'Hens privata', 'PRIVATE');
    $shared = e18bSave($this, $member, 'Hens delade');
    [, $other] = Bookkeeping::company();
    $other->users()->attach($member, ['role' => 'accountant']);
    CurrentCompany::run($other, fn () => ReportDefinition::create(['company_id' => $other->id, 'owner_user_id' => $member->id, 'name' => 'Annat företag', 'dataset' => 'ledger', 'definition' => ['dataset' => 'ledger'], 'visibility' => 'PRIVATE']));

    app(DeleteAccount::class)($member);

    expect(ReportDefinition::query()->withoutGlobalScopes()->whereKey($private->id)->exists())->toBeFalse()
        ->and(ReportDefinition::query()->withoutGlobalScopes()->where('name', 'Annat företag')->exists())->toBeFalse()
        ->and(ReportDefinition::query()->withoutGlobalScopes()->findOrFail($shared->id)->owner_user_id)->toBeNull();
});

it('lets whoever may share look after an ownerless company report, and nobody else', function () {
    $leaver = Bookkeeping::member($this->fixture->company, 'accountant');
    $accountant = Bookkeeping::member($this->fixture->company, 'accountant');
    $bookkeeper = Bookkeeping::member($this->fixture->company, 'bookkeeper');
    $report = e18bSave($this, $leaver, 'Föräldralös');
    $report->forceFill(['owner_user_id' => null])->save();
    $company = $this->fixture->company;

    expect(app(PinReport::class)($accountant, $company, $report, true)->pinned)->toBeTrue();

    $renamed = app(SaveReport::class)($accountant, $company, ['dataset' => 'ledger', 'name' => 'Omdöpt', 'visibility' => 'COMPANY', 'rolling' => false], $report);

    expect($renamed->name)->toBe('Omdöpt')
        ->and(app(PinReport::class)($accountant, $company, $report, false)->pinned)->toBeFalse()
        ->and(fn () => app(PinReport::class)($bookkeeper, $company, $report, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveReport::class)($bookkeeper, $company, ['dataset' => 'ledger', 'name' => 'Kapad', 'visibility' => 'COMPANY', 'rolling' => false], $report))->toThrow(AuthorizationException::class);

    app(DeleteReport::class)($accountant, $company, $report);

    expect(ReportDefinition::query()->whereKey($report->id)->exists())->toBeFalse();
});

it('lets a sharer unpin another person\'s company report but not pin it', function () {
    $report = e18bSave($this, $this->fixture->owner, 'Ägarens delade');
    app(PinReport::class)($this->fixture->owner, $this->fixture->company, $report, true);
    $accountant = Bookkeeping::member($this->fixture->company, 'accountant');
    $company = $this->fixture->company;

    expect(app(PinReport::class)($accountant, $company, $report, false)->pinned)->toBeFalse()
        ->and(fn () => app(PinReport::class)($accountant, $company, $report, true))->toThrow(AuthorizationException::class);
});

it('runs the same queries for the pinned list, whatever the number of reports', function () {
    $owner = $this->fixture->owner;
    $count = function () use ($owner): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        SavedReports::pinnedFor($owner, $this->fixture->company);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    app(PinReport::class)($owner, $this->fixture->company, e18bSave($this, $owner, 'En'), true);
    $count();
    $few = $count();

    for ($i = 0; $i < 6; $i++) {
        app(PinReport::class)($owner, $this->fixture->company, e18bSave($this, $owner, "Fler {$i}"), true);
    }

    expect($count())->toBe($few)->and(SavedReports::pinnedFor($owner, $this->fixture->company))->toHaveCount(SavedReports::MAX_TILES);
});

it('says so when the list of reports is cut', function () {
    for ($i = 0; $i < SavedReports::LIST_LIMIT + 1; $i++) {
        ReportDefinition::create(['company_id' => $this->fixture->company->id, 'owner_user_id' => $this->fixture->owner->id, 'name' => sprintf('R%03d', $i), 'dataset' => 'ledger', 'definition' => ['dataset' => 'ledger'], 'visibility' => 'PRIVATE']);
    }

    Livewire::test(AnalysisPage::class)->assertSee('Bara de första 200 rapporterna visas');
});

it('gives the export buttons and the row buttons names for screen readers', function () {
    e18bSave($this, $this->fixture->owner, 'Namngiven', 'PRIVATE');

    Livewire::test(AnalysisPage::class)->assertSeeHtml('aria-label="Hämta som CSV"')->assertSeeHtml('aria-label="Hämta som Excel"')->assertSeeHtml('aria-label="Hämta som PDF"')
        ->assertSeeHtml('<span class="sr-only"> Namngiven</span>');
});

it('adopts an ownerless company report when a sharer makes it private, so it is not stranded', function () {
    $leaver = Bookkeeping::member($this->fixture->company, 'accountant');
    $accountant = Bookkeeping::member($this->fixture->company, 'accountant');
    $report = e18bSave($this, $leaver, 'Föräldralös');
    $report->forceFill(['owner_user_id' => null])->save();

    $updated = app(SaveReport::class)($accountant, $this->fixture->company, ['dataset' => 'ledger', 'name' => 'Min nu', 'visibility' => 'PRIVATE', 'rolling' => false], $report);

    expect($updated->owner_user_id)->toBe($accountant->id)->and($updated->visibility)->toBe(ReportVisibility::Private)
        ->and($accountant->can('view', $updated))->toBeTrue()
        ->and($accountant->can('delete', $updated))->toBeTrue();
});

it('warns an owner who can no longer share that updating makes the report private', function () {
    $member = Bookkeeping::member($this->fixture->company, 'bookkeeper');
    $report = e18bSave($this, $this->fixture->owner, 'Delad av ägaren');
    $report->forceFill(['owner_user_id' => $member->id])->save();
    CurrentCompany::forget();

    try {
        $page = Livewire::actingAs($member)->withQueryParams(['rapport' => (string) $report->id])->test(AnalysisPage::class);
    } finally {
        Livewire::withQueryParams([]);
    }

    $page->assertSee('Du kan inte längre dela rapporter.');
});
