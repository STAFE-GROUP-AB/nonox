<?php

declare(strict_types=1);

use App\Actions\Company\AccountingFirmLink;
use App\Actions\Company\UpdateAccountingFirmSettings;
use App\Actions\Company\UpdateCompanyBranding;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Tasks\SaveTask;
use App\Domain\Company\AccountingFirms;
use App\Livewire\Auth\LoginForm;
use App\Livewire\Company\FirmSettings;
use App\Mail\CompanyInvitationMail;
use App\Mail\LoginCodeMail;
use App\Mail\TaskDigestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Branding\Brand;
use App\Support\Branding\FirmBranding;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Accounting-firm branding (ADR-030): a client that has chosen its firm is
 * shown in the firm's logo and colours to its own people; the firm's
 * consultants and the client's documents keep the client's own brand.
 */

beforeEach(function () {
    Storage::fake(Brand::DISK);
    Cache::flush();

    $this->firmOwner = User::factory()->withCompany(fn ($factory) => $factory->state(['name' => 'Byrå AB']))->create();
    $this->firm = $this->firmOwner->currentCompany();
    app(UpdateAccountingFirmSettings::class)($this->firmOwner, $this->firm, true, true);
    app(UpdateCompanyBranding::class)($this->firmOwner, $this->firm->fresh(), ['brand_color' => '#0B5D4B'], UploadedFile::fake()->image('byra.png', 240, 80));

    $this->clientOwner = User::factory()->withCompany(fn ($factory) => $factory->state(['name' => 'Kund AB', 'brand_color' => '#7A1F2B']))->create();
    $this->client = $this->clientOwner->currentCompany();

    // The consultant is in the firm and has the Redovisningskonsult role in the client: that connects the two.
    $this->consultant = User::factory()->create();
    $this->firm->users()->attach($this->consultant, ['role' => 'admin']);
    $this->client->users()->attach($this->consultant, ['role' => 'accountant']);
    $this->consultant->switchCompany($this->client);

    $this->links = app(AccountingFirmLink::class);
});

function firmAudit(Company $company): array
{
    return CompanyScope::bypass(fn () => AuditLog::query()->where('company_id', $company->id)->where('action', 'like', '%firm%')->pluck('action')->all());
}

function linkFirm(object $test): void
{
    $test->links->link($test->clientOwner, $test->client, $test->firm->id);
    $test->client->refresh();
}

it('offers the client owner only firms that one of its consultants is in, and audits the link in both companies', function () {
    $otherFirm = User::factory()->withCompany()->create()->currentCompany();
    $otherFirm->forceFill(['is_accounting_firm' => true])->save();

    expect(AccountingFirms::eligibleFor($this->client)->pluck('id')->all())->toBe([$this->firm->id]);

    linkFirm($this);

    expect($this->client->accounting_firm_id)->toBe($this->firm->id)
        ->and(firmAudit($this->client))->toContain('company.accounting_firm_linked')
        ->and(firmAudit($this->firm))->toContain('company.firm_client_linked');
});

it('refuses a firm without a shared consultant, a company that is not a firm, and anyone but the owner', function () {
    $stranger = User::factory()->withCompany()->create()->currentCompany();
    $stranger->forceFill(['is_accounting_firm' => true])->save();
    $notAFirm = User::factory()->withCompany()->create()->currentCompany();
    $notAFirm->users()->attach($this->consultant, ['role' => 'admin']);

    expect(fn () => $this->links->link($this->clientOwner, $this->client, $stranger->id))->toThrow(ValidationException::class)
        ->and(fn () => $this->links->link($this->clientOwner, $this->client, $notAFirm->id))->toThrow(ValidationException::class)
        ->and(fn () => $this->links->link($this->clientOwner, $this->client, $this->client->id))->toThrow(ValidationException::class)
        ->and(fn () => $this->links->link($this->consultant, $this->client, $this->firm->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateAccountingFirmSettings::class)($this->consultant, $this->firm, false, true))->toThrow(AuthorizationException::class);

    expect($this->client->fresh()->accounting_firm_id)->toBeNull();
});

it('lets either owner remove the link, audited in both companies', function () {
    linkFirm($this);
    $this->links->unlinkFromClient($this->clientOwner, $this->client);

    expect($this->client->fresh()->accounting_firm_id)->toBeNull()
        ->and(firmAudit($this->client))->toContain('company.accounting_firm_unlinked')
        ->and(firmAudit($this->firm))->toContain('company.firm_client_unlinked');

    linkFirm($this);
    $this->links->unlinkFromFirm($this->firmOwner, $this->firm, $this->client->id);
    expect($this->client->fresh()->accounting_firm_id)->toBeNull();

    // A company that is not the firm's client is "not found", and only the firm's owner may remove a link.
    $other = User::factory()->withCompany()->create()->currentCompany();
    expect(fn () => $this->links->unlinkFromFirm($this->firmOwner, $this->firm, $other->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->links->unlinkFromFirm($this->consultant, $this->firm, $this->client->id))->toThrow(AuthorizationException::class);
});

it('removes the links when the company stops being a firm or the last shared consultant leaves', function () {
    linkFirm($this);
    app(UpdateAccountingFirmSettings::class)($this->firmOwner, $this->firm->fresh(), false, true);
    expect($this->client->fresh()->accounting_firm_id)->toBeNull();

    app(UpdateAccountingFirmSettings::class)($this->firmOwner, $this->firm->fresh(), true, true);
    linkFirm($this);
    $this->actingAs($this->clientOwner);
    app(RemoveTeamMember::class)->remove($this->clientOwner, $this->client->fresh(), $this->consultant);

    expect($this->client->fresh()->accounting_firm_id)->toBeNull()
        ->and(CompanyScope::bypass(fn () => AuditLog::query()->where('company_id', $this->client->id)->where('action', 'company.accounting_firm_unlinked')->latest('id')->value('payload')))->toMatchArray(['by' => AccountingFirmLink::NO_CONSULTANT]);
});

it('gives the firm no access to the client by the link', function () {
    linkFirm($this);

    $this->actingAs($this->firmOwner)->get(route('teams.show', $this->client->id))->assertNotFound();
    $this->actingAs($this->firmOwner)->get(route('teams.firm-logo', $this->client->id))->assertNotFound();
    $this->actingAs($this->firmOwner)->get(route('teams.logo', $this->client->id))->assertNotFound();
    expect($this->firmOwner->fresh()->belongsToTeam($this->client))->toBeFalse();
});

it('shows the client side the firm\'s logo and colours in the app, and the consultant the client\'s own', function () {
    linkFirm($this);
    $firmAccent = '--accent-500: 11 93 75;';
    $clientAccent = '--accent-500: 122 31 43;';

    $this->actingAs($this->clientOwner->fresh())->get(route('dashboard'))->assertOk()
        ->assertSee($firmAccent, false)
        ->assertSee('i samarbete med')
        ->assertSee(route('teams.firm-logo', $this->client->id), false);
    $this->actingAs($this->clientOwner->fresh())->get(route('teams.firm-logo', $this->client->id))->assertOk()->assertHeader('Content-Type', 'image/png');

    $this->actingAs($this->consultant->fresh())->get(route('dashboard'))->assertOk()
        ->assertSee($clientAccent, false)
        ->assertDontSee('i samarbete med');

    // The firm's own workspace keeps the firm's own look.
    $this->actingAs($this->firmOwner->fresh())->get(route('dashboard'))->assertOk()->assertSee($firmAccent, false)->assertDontSee('i samarbete med');
});

it('falls back to the client\'s own brand when the firm turns the setting off', function () {
    linkFirm($this);
    app(UpdateAccountingFirmSettings::class)($this->firmOwner, $this->firm->fresh(), true, false);

    $this->actingAs($this->clientOwner->fresh())->get(route('dashboard'))->assertOk()
        ->assertSee('--accent-500: 122 31 43;', false)
        ->assertDontSee('i samarbete med');
    expect($this->client->fresh()->accounting_firm_id)->toBe($this->firm->id);
});

it('caches the firm\'s brand per firm, without a query per request, and forgets it when the firm changes', function () {
    linkFirm($this);
    FirmBranding::clientFacing($this->client);

    DB::enableQueryLog();
    $brand = FirmBranding::clientFacing($this->client);
    expect(DB::getQueryLog())->toBe([])
        ->and($brand->firmName)->toBe('Byrå AB');
    DB::disableQueryLog();

    app(UpdateCompanyBranding::class)($this->firmOwner, $this->firm->fresh(), ['brand_color' => '#123456']);
    expect(FirmBranding::clientFacing($this->client)->color)->toBe('#123456');

    $this->firm->fresh()->forceFill(['archived_at' => now()])->save();
    expect(FirmBranding::clientFacing($this->client)->viaFirm())->toBeFalse();
});

it('keeps text readable in a pale firm colour', function () {
    $this->firm->fresh()->forceFill(['brand_color' => '#FFE14D', 'brand_accent_color' => null])->save();
    linkFirm($this);
    $brand = FirmBranding::clientFacing($this->client);
    $hex = fn (string $rgb): string => sprintf('#%02X%02X%02X', ...array_map('intval', explode(' ', $rgb)));

    expect(Brand::contrast($brand->brand()->accentText(), '#FFFFFF'))->toBeGreaterThanOrEqual(4.5)
        ->and(Brand::contrast($hex($brand->palette()[600]), '#FFFFFF'))->toBeGreaterThanOrEqual(4.5)
        ->and(Brand::contrast($hex($brand->palette()[700]), '#FFFFFF'))->toBeGreaterThanOrEqual(4.5);
});

it('lets the owners manage it on the Varumärke tab', function () {
    $this->actingAs($this->clientOwner);
    Livewire::test(FirmSettings::class, ['company' => $this->client])
        ->assertSee('Vår redovisningsbyrå')
        ->assertSee('Byrå AB')
        ->set('firmId', (string) $this->firm->id)
        ->call('link')
        ->assertDispatched('toast')
        ->assertSee('Ta bort byrån');

    $this->actingAs($this->firmOwner);
    Livewire::test(FirmSettings::class, ['company' => $this->firm])
        ->assertSee('Kunder som har valt er')
        ->assertSee('Kund AB')
        ->call('removeClient', $this->client->id)
        ->assertDispatched('toast');

    expect($this->client->fresh()->accounting_firm_id)->toBeNull();

    $this->actingAs($this->consultant);
    Livewire::test(FirmSettings::class, ['company' => $this->client])->assertForbidden();
});

it('sends the login code in the firm\'s brand to the client side, and plain to the consultant', function () {
    linkFirm($this);
    Mail::fake();

    Livewire::test(LoginForm::class)->set('email', $this->clientOwner->email)->call('sendCode');
    Livewire::test(LoginForm::class)->set('email', $this->consultant->email)->call('sendCode');

    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail): bool {
        // The logo is an inline attachment (cid: when sent; a preview renders it as data).
        return $mail->hasTo($this->clientOwner->email)
            && $mail->mailBrand?->firmName === 'Byrå AB'
            && str_contains($mail->render(), 'Byrå AB · för Kund AB')
            && preg_match('/<img src="(cid:|data:image\/png)/', $mail->render()) === 1;
    });
    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail): bool => $mail->hasTo($this->consultant->email) && $mail->mailBrand === null && ! str_contains($mail->render(), 'Byrå AB'));
});

it('shows the firm\'s brand on the login page reached from an invitation to the client', function () {
    linkFirm($this);
    $invitation = $this->client->teamInvitations()->create(['email' => 'ny@example.test', 'role' => 'bookkeeper']);
    $signed = URL::temporarySignedRoute('team-invitations.accept', now()->addDay(), ['invitation' => $invitation->id]);

    $this->get(route('login'))->assertOk()->assertDontSee('Byrå AB');

    $this->get($signed)->assertRedirect(route('login'))->assertSessionHas('url.intended', $signed);
    $this->withSession(['url.intended' => $signed])->get(route('login'))->assertOk()->assertSee('Byrå AB · för Kund AB')->assertSee('byrans-logotyp', false);

    // The signed logo address works without a login; a tampered one does not.
    $logo = FirmBranding::clientFacing($this->client)->signedLogoUrl();
    $this->get($logo)->assertOk();
    $this->get(route('brand.firm-logo', $this->client->id))->assertForbidden();

    // An unsigned invitation address brands nothing.
    $this->withSession(['url.intended' => route('team-invitations.accept', $invitation->id)])->get(route('login'))->assertOk()->assertDontSee('Byrå AB');

    // The invitation email carries the brand too, but not for a consultant invited as the firm.
    expect((new CompanyInvitationMail($invitation))->render())->toContain('Byrå AB · för Kund AB');
    $consultantInvite = $this->client->teamInvitations()->create(['email' => 'konsult@example.test', 'role' => 'accountant']);
    expect((new CompanyInvitationMail($consultantInvite))->render())->not->toContain('Byrå AB');
});

it('brands the task digest when all of the user\'s tasks are in companies that show the same firm', function () {
    linkFirm($this);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00:00'));
    $this->clientOwner->forceFill(['task_digest' => 'DAILY'])->save();
    $this->consultant->forceFill(['task_digest' => 'DAILY'])->save();
    app(SaveTask::class)($this->clientOwner, $this->client, ['title' => 'Skicka kvitton', 'type' => 'DOCUMENTS', 'assignee_id' => $this->clientOwner->id]);
    app(SaveTask::class)($this->clientOwner, $this->client, ['title' => 'Moms', 'type' => 'VAT', 'assignee_id' => $this->consultant->id]);

    $this->artisan('tasks:send-digest')->assertSuccessful();

    Mail::assertSent(TaskDigestMail::class, fn (TaskDigestMail $mail): bool => $mail->hasTo($this->clientOwner->email)
        && $mail->mailBrand?->firmName === 'Byrå AB' && str_contains($mail->render(), 'Byrå AB · för Kund AB'));
    Mail::assertSent(TaskDigestMail::class, fn (TaskDigestMail $mail): bool => $mail->hasTo($this->consultant->email) && $mail->mailBrand === null);
});
