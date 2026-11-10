<?php

declare(strict_types=1);

use App\Actions\Waitlist\ApproveWaitlistEntry;
use App\Domain\Company\Industry;
use App\Domain\Waitlist\OnboardingPlan;
use App\Mail\WaitlistApprovedMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Onboarding;

/*
 * The personal welcome mail after approval (ADR-038 addendum): built from the
 * answers on the waiting list, signed by Andreas, replies go to him.
 */

function welcomeHtml(WaitlistEntry $entry): string
{
    return (new WaitlistApprovedMail($entry))->render();
}

it('names the person and the company', function () {
    [$entry] = Onboarding::approved(['name' => 'Kim Exempel', 'company_name' => 'Exempelkaféet AB']);

    $html = welcomeHtml($entry);

    expect($html)->toContain('Hej Kim')->toContain('Exempelkaféet AB');
});

it('points a Fortnox user to the automatic move and the manual article', function () {
    [$entry] = Onboarding::approved(['current_system' => 'fortnox']);

    $html = welcomeHtml($entry);

    expect($html)->toContain(route('fortnox.move'))
        ->toContain(url('/manual/flytta/fran-fortnox'));
});

it('points a user of another program to a SIE file and to importing the registers', function () {
    [$entry] = Onboarding::approved(['current_system' => 'visma']);

    $html = welcomeHtml($entry);

    expect($html)->toContain('SIE')
        ->toContain(route('import.registers.index'))
        ->toContain(url('/manual/flytta/fran-andra-program'))
        ->not->toContain(route('fortnox.move'));
});

it('tells someone with no program to start from scratch', function () {
    [$entry] = Onboarding::approved(['current_system' => 'inget']);

    $html = welcomeHtml($entry);

    expect($html)->toContain('från början')
        ->toContain(url('/manual/kom-igang/skapa-ett-foretag'))
        ->not->toContain(route('fortnox.move'))
        ->not->toContain(route('import.registers.index'));
});

it('points to payroll and moving payroll data only when there are employees', function () {
    [$with] = Onboarding::approved(['employees' => '5-9']);
    [$without] = Onboarding::approved(['employees' => 'inga']);

    expect(welcomeHtml($with))->toContain(url('/manual/lon'))->toContain(url('/manual/lon/flytt-av-lonedata'))
        ->and(welcomeHtml($without))->not->toContain(url('/manual/lon/flytt-av-lonedata'));
});

it('suggests the modules of the industry with links to their manual sections', function () {
    [$entry] = Onboarding::approved(['industry' => 'bokforing-for-cafeer']);

    $html = welcomeHtml($entry);

    expect($html)->toContain(url('/manual/kok'))
        ->toContain(url('/manual/lager'))
        ->toContain(route('login'));
});

it('only ever suggests modules that exist', function () {
    $registry = app(ModuleRegistry::class);

    foreach (Industry::cases() as $industry) {
        foreach (OnboardingPlan::moduleKeysFor($industry) as $key) {
            expect($registry->has($key))->toBeTrue("{$industry->value}: {$key}");
        }
    }

    expect(OnboardingPlan::industryFor('bokforing-for-cafeer'))->toBe(Industry::Cafe)
        ->and(OnboardingPlan::industryFor('annat'))->toBeNull()
        ->and(OnboardingPlan::industryFor('finns-inte'))->toBeNull();
});

it('is sent from the normal address, replies to Andreas and is signed by him', function () {
    [$entry] = Onboarding::approved();
    $mail = new WaitlistApprovedMail($entry);

    $envelope = $mail->envelope();
    $html = $mail->render();

    expect($envelope->from)->toBeNull()
        ->and($envelope->replyTo)->toHaveCount(1)
        ->and($envelope->replyTo[0]->address)->toBe('andreas@stafegroup.com')
        ->and($html)->toContain('Med vänliga hälsningar')
        ->toContain('Andreas Kviby')
        ->toContain('andreas@stafegroup.com')
        ->toContain(config('mail.from.address'));
});

it('keeps the beta sentence with the launch date from the config and has no booking link', function () {
    [$entry] = Onboarding::approved();

    $html = welcomeHtml($entry);

    expect($html)->toContain('öppen beta')->toContain('1 februari 2027')
        ->not->toContain('calendly')->not->toContain('cal.com')->not->toContain('boka ett');
});

it('is queued by the approval with the entry in it', function () {
    Mail::fake();
    config(['nonox.hq_admins' => ['hq@example.test']]);
    $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    $entry = WaitlistEntry::factory()->create(['name' => 'Kim Exempel', 'email' => 'kim@example.test', 'company_name' => 'Kims AB']);

    app(ApproveWaitlistEntry::class)($admin, $entry);

    Mail::assertQueued(WaitlistApprovedMail::class, fn (WaitlistApprovedMail $mail): bool => $mail->hasTo('kim@example.test') && $mail->entry->company_name === 'Kims AB');

    $audit = AuditLog::query()->where('action', 'waitlist.approved')->latest('id')->firstOrFail();
    expect(json_encode($audit->payload))->not->toContain('Kims AB')->not->toContain('kim@example.test');
});

it('links only to manual sections and articles that exist', function () {
    foreach (OnboardingPlan::MANUAL as $key => $path) {
        expect(is_dir(base_path("docs/manual/sv/{$path}")) || is_file(base_path("docs/manual/sv/{$path}.md")))->toBeTrue("{$key}: {$path}");
    }

    foreach (['kom-igang/skapa-ett-foretag', 'flytta/fran-fortnox', 'flytta/fran-andra-program', 'lon/flytt-av-lonedata'] as $path) {
        expect(is_file(base_path("docs/manual/sv/{$path}.md")))->toBeTrue($path);
    }
});
