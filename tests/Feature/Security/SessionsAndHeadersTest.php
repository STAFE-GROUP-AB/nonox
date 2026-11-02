<?php

declare(strict_types=1);

use App\Http\Middleware\EndExpiredSessions;
use App\Livewire\Profile\SignOutEverywhere;
use App\Models\User;
use App\Support\Csv;
use App\Support\FileName;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\StaffAccess;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

it('sends security headers on every page', function () {
    $this->get('/')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob:; worker-src 'self' blob:; frame-src 'self' blob:; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
});

it('ends a session after the absolute limit', function () {
    $user = User::factory()->withCompany()->create();

    $this->actingAs($user)
        ->withSession([EndExpiredSessions::STARTED => time() - (EndExpiredSessions::ABSOLUTE_HOURS * 3600) - 1])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('signs out every other session but keeps this one', function () {
    $user = User::factory()->withCompany()->create();
    $this->actingAs($user);

    Livewire::test(SignOutEverywhere::class)->call('signOutOthers');

    expect($user->fresh()->sessions_valid_from)->not->toBeNull();

    $this->withSession([EndExpiredSessions::STARTED => CarbonImmutable::now()->subMinute()->getTimestamp()])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('keeps employees who only use Min sida out of staff pages', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    Payroll::setUp($company, $owner);
    $employee = Bookkeeping::member($company, 'employee');
    $staff = Bookkeeping::member($company, 'staff_ledger_staff');

    expect(StaffAccess::allows($owner, $company))->toBeTrue()
        ->and(StaffAccess::allows($employee, $company))->toBeFalse()
        ->and(StaffAccess::allows($staff, $company))->toBeFalse();
});

it('cleans uploaded file names so they can always be downloaded', function () {
    expect(FileName::clean('..\\kvitto\\mars.pdf'))->toBe('_kvitto_mars.pdf')
        ->and(FileName::clean("a\x00b.pdf"))->toBe('a_b.pdf')
        ->and(Csv::cell('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")
        ->and(Csv::cell('-125,00'))->toBe('-125,00');
});

it('caps the e-mail a company can send in an hour', function () {
    [, $company] = Bookkeeping::company();

    for ($i = 0; $i < MailQuota::LIMITS['invitations']; $i++) {
        MailQuota::take($company, 'invitations');
    }

    expect(fn () => MailQuota::take($company, 'invitations'))->toThrow(ValidationException::class);
});

it('does not let a company name become a link in a mail', function () {
    $name = 'Acme [Bekräfta här](https://evil.example)';
    $rendered = (string) app(Markdown::class)->render('emails.team-invitation', [
        'invitation' => (object) ['team' => (object) ['name' => $name]],
        'acceptUrl' => 'https://nonox.test/inbjudan',
    ]);

    expect($rendered)->toContain('Acme')->not->toContain('href="https://evil.example"');
});

it('draws the default profile photo itself instead of sending the name to a third party', function () {
    $user = User::factory()->make(['name' => 'Anna Åberg']);

    expect($user->profile_photo_url)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($user->profile_photo_url, 26)))->toContain('AÅ')
        ->and($user->profile_photo_url)->not->toContain('ui-avatars');
});
