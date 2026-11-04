<?php

declare(strict_types=1);

use App\Actions\Privacy\EraseRegisterEntry;
use App\Models\AuditLog;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\RunAgreementReminders;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\Actions\SendForSigning;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AgreementCard;
use App\Modules\Crm\Livewire\AgreementsPage;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\SigningRequest;
use App\Modules\Crm\Support\CrmTodos;
use App\Modules\Crm\Support\PruneCandidates;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The agreement register (ADR-046): agreements per account with term,
 * notice and renewal, a daily run that renews and reminds (idempotent),
 * "Att göra", the Avtal pages and tab, and signing an agreement.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB');
    $this->account = Crm::accountFor($this->customer);
});

function agreement(object $test, array $extra = []): Agreement
{
    return app(SaveAgreement::class)($test->owner, $test->company, [
        'account_id' => $test->account->id,
        'title' => 'Serviceavtal kaffemaskiner',
        'kind' => 'SUPPORT',
        'start_date' => '2025-09-01',
        'end_date' => '2026-08-31',
        'notice_months' => 1,
        'auto_renew' => true,
        'renewal_months' => 12,
        'value' => '2 500',
        'value_period' => 'MONTH',
        ...$extra,
    ]);
}

it('saves an agreement with its terms, value in öre and a notice deadline, and audits ids only', function () {
    $agreement = agreement($this);

    expect($agreement->value_ore)->toBe(250_000)
        ->and($agreement->noticeDeadline()?->toDateString())->toBe('2026-07-31')
        ->and($agreement->renews())->toBeTrue()
        ->and($agreement->status(CarbonImmutable::parse('2026-06-15')))->toBe(Agreement::ACTIVE)
        ->and(json_encode(AuditLog::query()->where('action', 'crm.agreement_created')->sole()->payload))->not->toContain('kaffemaskiner');
});

it('needs an end date and a term to renew automatically, and an end on or after the start', function () {
    expect(fn () => agreement($this, ['end_date' => null]))->toThrow(ValidationException::class, 'slutdatum för den första perioden')
        ->and(fn () => agreement($this, ['renewal_months' => null]))->toThrow(ValidationException::class, 'förlängs med')
        ->and(fn () => agreement($this, ['end_date' => '2025-01-01']))->toThrow(ValidationException::class, 'före startdatumet');
});

it('lets a seller manage agreements but not an auditor', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(app(SaveAgreement::class)($seller, $this->company, ['account_id' => $this->account->id, 'title' => 'Säljarens avtal', 'kind' => 'SERVICE', 'start_date' => '2026-06-01', 'notice_months' => 0, 'value_period' => 'MONTH'])->title)->toBe('Säljarens avtal')
        ->and(fn () => agreement((object) ['owner' => $auditor, 'company' => $this->company, 'account' => $this->account]))->toThrow(AuthorizationException::class);
});

it('reminds the owner once per period before the notice deadline, renews when the period passes, and reminds again', function () {
    $agreement = agreement($this, ['owner_id' => $this->owner->id]);
    $run = app(RunAgreementReminders::class);

    // 2026-06-15: deadline 2026-07-31 is 46 days ahead, within the default 60.
    expect($run($this->company, CarbonImmutable::parse('2026-06-15')))->toBe([0, 1, 0])
        ->and($run($this->company, CarbonImmutable::parse('2026-06-16')))->toBe([0, 0, 0]);

    $task = Activity::query()->where('account_id', $this->account->id)->sole();
    expect($task->type->value)->toBe('TASK')
        ->and($task->assigned_to)->toBe($this->owner->id)
        ->and($task->subject)->toContain('förlängs 2026-08-31')
        ->and($task->due_at?->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'))->toBe('2026-07-31 09:00');

    expect($run($this->company, CarbonImmutable::parse('2026-09-02')))->toBe([1, 0, 0])
        ->and($agreement->refresh()->end_date?->toDateString())->toBe('2027-08-31')
        ->and($run($this->company, CarbonImmutable::parse('2027-06-15')))->toBe([0, 1, 0])
        ->and(Activity::query()->where('account_id', $this->account->id)->count())->toBe(2);
});

it('does not renew or remind a terminated agreement, which then ends', function () {
    $agreement = agreement($this);
    app(SaveAgreement::class)->terminate($this->owner, $this->company, $agreement, '2026-06-10');
    $run = app(RunAgreementReminders::class);

    expect($run($this->company, CarbonImmutable::parse('2026-06-15')))->toBe([0, 0, 0])
        ->and($run($this->company, CarbonImmutable::parse('2026-09-02')))->toBe([0, 0, 0])
        ->and($agreement->refresh()->status(CarbonImmutable::parse('2026-09-02')))->toBe(Agreement::ENDED);
});

it('lists agreements near their notice deadline in Att göra and on Avtal with a window', function () {
    agreement($this, ['owner_id' => $this->owner->id]);
    agreement($this, ['title' => 'Långt fram', 'start_date' => '2026-01-01', 'end_date' => '2027-12-31']);

    $todos = app(CrmTodos::class)->todos($this->owner, $this->company, CarbonImmutable::parse('2026-06-15'));
    expect(collect($todos)->firstWhere('key', 'crm-agreements-renewing')?->title)->toBe('1 avtal närmar sig uppsägningsdagen');

    Livewire::test(AgreementsPage::class)->assertSee('Serviceavtal kaffemaskiner')->assertSee('Långt fram')
        ->set('show', 'loper-ut')->set('within', 90)->assertSee('Serviceavtal kaffemaskiner')->assertDontSee('Långt fram')
        ->set('search', 'kontorshuset')->assertSee('Serviceavtal kaffemaskiner');
});

it('shows the Avtal tab on the account card and opens a new agreement for the account', function () {
    agreement($this);

    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])
        ->set('tab', 'avtal')->assertSee('Serviceavtal kaffemaskiner')->assertSeeHtml(route('crm.agreements.create', ['konto' => $this->account->id]));

    $this->get(route('crm.agreements.create', ['konto' => $this->account->id]))->assertOk()->assertSee('Nytt avtal');
    $this->get(route('crm.agreements'))->assertOk()->assertSee('Avtal');
});

it('saves an agreement on its card, attaches a PDF and terminates it', function () {
    $agreement = agreement($this);
    $pdf = UploadedFile::fake()->createWithContent('avtal.pdf', "%PDF-1.4\n%test\n");

    Livewire::test(AgreementCard::class, ['agreementId' => $agreement->id])
        ->set('form.title', 'Serviceavtal 2026')->call('save')->assertHasNoErrors()
        ->set('file', $pdf)->call('upload')->assertHasNoErrors()
        ->call('terminate')->assertHasNoErrors();

    $agreement->refresh();
    expect($agreement->title)->toBe('Serviceavtal 2026')
        ->and($agreement->document_sha256)->toBe(hash('sha256', "%PDF-1.4\n%test\n"))
        ->and($agreement->terminated_on?->toDateString())->toBe('2026-06-15');

    expect(fn () => app(SaveAgreement::class)->attach($this->owner, $this->company, $agreement, UploadedFile::fake()->createWithContent('inte.pdf', 'hej')))->toThrow(ValidationException::class);
});

it('signs an agreement through the same flow and then locks its terms', function () {
    $agreement = agreement($this, ['body_text' => 'Leverantören servar maskinerna en gång per kvartal.']);
    $sent = app(SendForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'anna@kontorshuset.test']);
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));

    $this->get("/signera/{$token}")->assertOk()->assertSee('Serviceavtal kaffemaskiner')->assertSee('Leverantören servar')->assertSee('Jag har läst och godkänner avtalet och har rätt att ingå det för Kontorshuset AB.');
    $this->post("/signera/{$token}", ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Anna Andersson', 'document_sha256' => $sent['request']->document_sha256])->assertOk();
    CurrentCompany::set($this->company);

    $agreement->refresh();
    expect($agreement->signed_at)->not->toBeNull()
        ->and($agreement->document_sha256)->toBe($sent['request']->document_sha256)
        ->and(Activity::query()->where('account_id', $this->account->id)->where('subject', 'Avtalet ”Serviceavtal kaffemaskiner” signerades')->exists())->toBeTrue()
        ->and(fn () => app(SaveAgreement::class)($this->owner, $this->company, [...$agreement->only(['account_id', 'title', 'notice_months', 'renewal_months', 'value_ore']), 'start_date' => '2025-09-01', 'kind' => 'SUPPORT', 'value_period' => 'MONTH', 'auto_renew' => true, 'end_date' => '2027-08-31'], $agreement))->toThrow(ValidationException::class, 'Avtalet är signerat')
        ->and(fn () => app(SendForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'a@b.test']))->toThrow(ValidationException::class, 'redan signerat');

    // A signed agreement keeps its prospect from erasure and pruning.
    $prospect = Crm::prospect($this->company, $this->owner, 'Prospektet AB');
    $other = agreement($this, ['account_id' => $prospect->id]);
    $other->forceFill(['signed_at' => now()])->save();
    expect(fn () => app(EraseCrmData::class)->prospect($this->owner, $this->company, $prospect))->toThrow(ValidationException::class, 'signerat avtal')
        ->and(PruneCandidates::prospects(CarbonImmutable::parse('2030-01-01'))->whereKey($prospect->id)->exists())->toBeFalse();
});

it('prunes links that were never signed a year after they closed, with their PDF, and keeps signed ones', function () {
    $agreement = agreement($this);
    $sent = app(SendForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'anna@kontorshuset.test', 'expires_on' => '2026-06-20']);
    $path = $sent['request']->document_path;

    expect(app(RunAgreementReminders::class)($this->company, CarbonImmutable::parse('2027-06-19'))[2])->toBe(0)
        ->and(app(RunAgreementReminders::class)($this->company, CarbonImmutable::parse('2027-06-22'))[2])->toBe(1)
        ->and(SigningRequest::query()->whereKey($sent['request']->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(DB::table('crm_signing_events')->where('signing_request_id', $sent['request']->id)->count())->toBe(0);
});

it('runs the daily command for every company with CRM on', function () {
    agreement($this);

    $this->artisan('crm:agreements')->expectsOutputToContain('reminders created')->assertSuccessful();
});

it('anonymises a customer with a signed agreement instead of deleting it, so the agreement and its evidence stay', function () {
    $agreement = agreement($this);
    $agreement->forceFill(['signed_at' => now()])->save();

    expect(app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $this->customer))->toBe('anonymised')
        ->and(Agreement::query()->whereKey($agreement->id)->exists())->toBeTrue();
});

it('deletes the PDFs of an erased prospect\'s agreements and signing links with it', function () {
    $prospect = Crm::prospect($this->company, $this->owner, 'Prospektet AB');
    $draft = agreement($this, ['account_id' => $prospect->id]);
    app(SaveAgreement::class)->attach($this->owner, $this->company, $draft, UploadedFile::fake()->createWithContent('avtal.pdf', "%PDF-1.4\n%x\n"));
    $sent = app(SendForSigning::class)($this->owner, $this->company, $draft->refresh(), ['email' => 'anna@prospektet.test']);
    $paths = [(string) $draft->refresh()->document_path, $sent['request']->document_path];

    app(EraseCrmData::class)->prospect($this->owner, $this->company, $prospect);

    expect(Storage::disk('local')->exists($paths[0]))->toBeFalse()
        ->and(Storage::disk('local')->exists($paths[1]))->toBeFalse()
        ->and(Agreement::query()->whereKey($draft->id)->exists())->toBeFalse();
});
