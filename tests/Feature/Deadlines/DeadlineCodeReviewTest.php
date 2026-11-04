<?php

declare(strict_types=1);

use App\Actions\Guide\IssueDeadlineFeed;
use App\Console\Commands\PrunePersonalDataCommand;
use App\Console\Commands\SendDeadlineRemindersCommand;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineCompletion;
use App\Domain\Deadlines\DeadlineType;
use App\Livewire\Guide\MyDates;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DeadlineCalendarFeed;
use App\Models\DeadlineReminder;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VatReport;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * Mina datum after the independent code review of stage 2
 * (scratchpad datum-code-review.md): the guide switch, the reminder
 * command's memory and atomicity, Översikt, the payment date, links to
 * modules that are off, the empty state, the audit actor, one active link,
 * the feed's ETag and pruning of stopped links.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly, 'turnover_over_40_msek' => false])->save();
    CurrentCompany::set($this->company);
});

it('shows a friendly page instead of Mina datum, and makes no link, when the company has turned the guide off', function () {
    $this->company->forceFill(['guide_enabled' => false])->save();

    $this->actingAs($this->owner->fresh())->get(route('guide.dates'))->assertOk()
        ->assertSee('Företagsguiden är avstängd för företaget')
        ->assertSee(route('teams.show', $this->company->id), false)
        ->assertDontSee('data-dates-month', false)
        ->assertDontSee('Skapa kalenderlänk');

    $this->actingAs($this->owner->fresh());
    Livewire::test(MyDates::class)->call('issueLink')->assertNotFound();
    expect(DeadlineCalendarFeed::query()->count())->toBe(0);
});

it('rolls a reminder back when its notice fails, so the next run sends it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 06:50:00'));
    Event::listen(NotificationSending::class, function (): void {
        throw new RuntimeException('The notice could not be stored.');
    });

    rescue(fn () => $this->artisan(SendDeadlineRemindersCommand::class)->run(), report: false);

    expect(CompanyScope::bypass(fn (): int => DeadlineReminder::query()->count()))->toBe(0);
});

it('reads companies in chunks, with a fixed number of queries per company', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 06:50:00'));
    $count = function (): int {
        $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();

        return count(DB::getQueryLog());
    };

    $one = $count();
    Bookkeeping::company();
    $two = $count();
    Bookkeeping::company();
    Bookkeeping::company();
    $four = $count();

    expect($four - $two)->toBe(2 * ($two - $one));
});

it('leaves reported VAT and AGI periods out of Nästa datum on Översikt', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company);
    CurrentCompany::set($this->company);
    VatReport::create(['period_start' => '2026-07-01', 'period_end' => '2026-09-30', 'boxes' => [49 => 0], 'voucher_id' => $voucher->id, 'created_by' => $this->owner->id]);

    $html = (string) $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
    $card = substr($html, (int) strpos($html, 'data-next-dates'));

    expect($card)->not->toContain('Deklarera och betala moms för kvartal 3 2026');
});

it('never counts a large employer\'s payment date as done because the declaration is filed', function () {
    $payment = new Deadline('agi-payment:2026-10', DeadlineType::Agi, 'Betala', CarbonImmutable::parse('2026-11-12'), 'oktober 2026', '', periodStart: CarbonImmutable::parse('2026-10-01'));
    $declaration = new Deadline('agi:2026-10', DeadlineType::Agi, 'Deklarera', CarbonImmutable::parse('2026-11-26'), 'oktober 2026', '', periodStart: CarbonImmutable::parse('2026-10-01'));
    AgiSubmission::query()->insert(AgiSubmissionRow::for($this->company, $this->owner, '2026-10'));

    expect(DeadlineCompletion::done($this->company, [$payment, $declaration]))->toBe(['agi:2026-10']);
});

it('links the employer declaration to NONOX only while the payroll module is on', function () {
    $this->company->forceFill(['employer_registered' => true])->save();
    app()->forgetScopedInstances();

    $agi = collect(app(CompanyDeadlines::class)->upcoming($this->company->fresh(), CarbonImmutable::today()))->firstWhere('type', DeadlineType::Agi);
    expect($agi->route)->toBeNull();

    Payroll::setUp($this->company, $this->owner);
    app()->forgetScopedInstances();

    expect(collect(app(CompanyDeadlines::class)->upcoming($this->company->fresh(), CarbonImmutable::today()))->firstWhere('type', DeadlineType::Agi)->route)->toBe('payroll.agi');
});

it('says six months, not fifteen, when the default view is empty, and offers all of them', function () {
    $this->company->forceFill(['vat_registered' => false, 'vat_period' => VatPeriod::None, 'f_skatt' => false])->save();
    FiscalYear::query()->delete();
    FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

    $this->actingAs($this->owner->fresh())->get(route('guide.dates'))->assertOk()
        ->assertSee('Inga datum de kommande sex månaderna')
        ->assertSee(route('guide.dates', ['alla' => 1]), false);
});

it('records the one who removed a member as the one who stopped the member\'s link', function () {
    $member = Bookkeeping::member($this->company, 'admin');
    app(IssueDeadlineFeed::class)($member, $this->company->fresh());

    $this->actingAs($this->owner);
    app(RemovesTeamMembers::class)->remove($this->owner, $this->company->fresh(), $member);

    $log = CompanyScope::bypass(fn () => AuditLog::query()->where('action', 'guide.calendar_feed_revoked')->sole());
    expect($log->user_id)->toBe($this->owner->id)->and($log->payload['user_id'])->toBe($member->id);
});

it('allows one active calendar link per user and company in the database', function () {
    app(IssueDeadlineFeed::class)($this->owner, $this->company);

    expect(fn () => DB::transaction(fn () => DB::table('deadline_calendar_feeds')->insert([
        'company_id' => $this->company->id, 'user_id' => $this->owner->id, 'token_hash' => hash('sha256', 'en-annan'), 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('answers 304 to a calendar that already has the current feed', function () {
    $link = app(IssueDeadlineFeed::class)($this->owner, $this->company);
    $etag = $this->get($link)->assertOk()->headers->get('ETag');

    expect($etag)->not->toBeNull();
    $this->get($link, ['If-None-Match' => $etag])->assertStatus(304);
});

it('prunes stopped calendar links after 30 days', function () {
    app(IssueDeadlineFeed::class)($this->owner, $this->company);
    app(IssueDeadlineFeed::class)($this->owner, $this->company);
    CompanyScope::bypass(fn () => DeadlineCalendarFeed::query()->whereNotNull('revoked_at')->update(['revoked_at' => now()->subDays(31)]));

    $this->artisan(PrunePersonalDataCommand::class)->assertSuccessful();

    expect(CompanyScope::bypass(fn (): int => DeadlineCalendarFeed::query()->count()))->toBe(1)
        ->and(PrunePersonalDataCommand::RETENTION['revoked_calendar_links'])->toBe(30);
});

/** A submitted employer declaration, for the completion check. */
final class AgiSubmissionRow
{
    /** @return array<string, mixed> */
    public static function for(Company $company, User $user, string $period): array
    {
        return array_intersect_key([
            'company_id' => $company->id,
            'period' => $period,
            'sequence' => 1,
            'status' => AgiSubmission::SUBMITTED,
            'created_by' => $user->id,
            'generated_by' => $user->id,
            'xml_path' => 'agi/test.xml',
            'sha256' => str_repeat('a', 64),
            'tax_ore' => 0,
            'contributions_ore' => 0,
            'individuals' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ], array_flip(Schema::getColumnListing('agi_submissions')));
    }
}
