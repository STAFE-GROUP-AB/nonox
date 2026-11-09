<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Livewire\Hq\Settings;
use App\Models\AuditLog;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Support\Instance\InstanceSettings;
use App\Support\Status\SecurityTxt;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * /.well-known/security.txt (RFC 9116) and the Swedish security policy
 * (ADR-078). Contact and Expires are required by the RFC, Expires is once
 * and less than a year ahead, web addresses are https, and the file is
 * text/plain in UTF-8.
 */

function contactSetting(?string $value): void
{
    InstanceSetting::query()->updateOrCreate(['key' => InstanceSettings::SECURITY_CONTACT], ['value' => $value]);
    InstanceSettings::current()->forget();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 10:00:00', 'Europe/Stockholm'));
    config([
        'app.landing.enabled' => true,
        'app.landing.security_email' => null,
        'app.landing.privacy_email' => null,
        'nonox.hq_admins' => ['hq@example.test'],
        'app.admin_email' => null,
    ]);
    InstanceSettings::current()->forget();
});

it('serves a valid security.txt as UTF-8 plain text at the well-known address', function () {
    config(['app.landing.security_email' => 'sakerhet@example.test']);

    $response = $this->get('/.well-known/security.txt')->assertOk();
    $body = $response->getContent();
    $lines = array_values(array_filter(explode("\n", $body), fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));

    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8')
        ->and($body)->toEndWith("\n")
        ->and($body)->not->toContain("\r")
        ->and($lines)->toContain('Contact: mailto:sakerhet@example.test')
        ->and($lines)->toContain('Preferred-Languages: sv, en')
        ->and($lines)->toContain('Canonical: '.SecurityTxt::baseUrl().'/.well-known/security.txt')
        ->and(collect($lines)->filter(fn (string $line): bool => str_starts_with($line, 'Expires: '))->count())->toBe(1)
        ->and(collect($lines)->filter(fn (string $line): bool => str_starts_with($line, 'Policy: '))->count())->toBe(0);
});

it('adds the Policy line only when the policy page is published, and always as https', function () {
    config(['app.landing.security_email' => 'sakerhet@example.test', 'app.landing.hosted' => true, 'app.landing.security_policy_published' => true]);

    $policy = collect(explode("\n", (string) $this->get('/.well-known/security.txt')->getContent()))->first(fn (string $line): bool => str_starts_with($line, 'Policy: '));

    expect($policy)->toStartWith('Policy: https://')->and($policy)->toEndWith('/sakerhetspolicy');

    config(['app.landing.security_policy_published' => false]);

    $this->get('/.well-known/security.txt')->assertOk()->assertDontSee('Policy:', false)->assertSee('Contact: mailto:sakerhet@example.test', false);
});

it('builds Canonical and Policy from APP_URL and not from the Host header', function () {
    config(['app.url' => 'http://min-bokforing.example', 'app.landing.security_email' => 'sakerhet@example.test', 'app.landing.hosted' => true, 'app.landing.security_policy_published' => true]);

    $body = (string) $this->withServerVariables(['HTTP_HOST' => 'evil.example'])->get('/.well-known/security.txt')->assertOk()->getContent();

    expect($body)->toContain('Canonical: https://min-bokforing.example/.well-known/security.txt')
        ->and($body)->toContain('Policy: https://min-bokforing.example/sakerhetspolicy')
        ->and($body)->not->toContain('evil.example');
});

it('sets Expires to the day the contact was confirmed plus 365 days, and does not renew it by being read', function () {
    config(['app.landing.privacy_email' => null]);
    contactSetting('sakerhet@example.test');

    $read = function (): CarbonImmutable {
        preg_match('/^Expires: (.+)$/m', (string) test()->get('/.well-known/security.txt')->getContent(), $match);
        expect($match[1])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

        return CarbonImmutable::parse($match[1]);
    };

    $first = $read();

    expect($first->equalTo(now()->utc()->addDays(365)))->toBeTrue()
        ->and($first->greaterThan(now()))->toBeTrue()
        ->and($first->lessThanOrEqualTo(now()->addDays(365)))->toBeTrue();

    $this->travel(100)->days();

    expect($read()->equalTo($first))->toBeTrue();

    $this->travel(300)->days();

    expect($read()->lessThan(now()))->toBeTrue();
});

it('takes the contact from the instance setting before the environment', function () {
    config(['app.landing.security_email' => 'fran-miljon@example.test', 'app.landing.privacy_email' => 'integritet@example.test']);

    expect(SecurityTxt::contact())->toBe('mailto:fran-miljon@example.test');

    contactSetting('https://example.test/rapportera');
    $this->get('/.well-known/security.txt')->assertOk()->assertSee('Contact: https://example.test/rapportera', false);

    contactSetting('egen@example.test');
    $this->get('/.well-known/security.txt')->assertOk()->assertSee('Contact: mailto:egen@example.test', false)->assertDontSee('fran-miljon');
});

it('answers 404 when the installation has no security contact, since Contact is required', function () {
    $this->get('/.well-known/security.txt')->assertNotFound();
});

it('accepts an e-mail address or an https address as contact and nothing else', function () {
    expect(SecurityTxt::contactUri('a@example.test'))->toBe('mailto:a@example.test')
        ->and(SecurityTxt::contactUri('mailto:a@example.test'))->toBe('mailto:a@example.test')
        ->and(SecurityTxt::contactUri('https://example.test/x'))->toBe('https://example.test/x')
        ->and(SecurityTxt::contactUri('http://example.test/x'))->toBeNull()
        ->and(SecurityTxt::contactUri('+46 8 123 45'))->toBeNull()
        ->and(SecurityTxt::contactUri("a@example.test\nExpires: 2099-01-01T00:00:00Z"))->toBeNull()
        ->and(SecurityTxt::contactUri(''))->toBeNull();
});

it('redirects the legacy address to the well-known one', function () {
    $this->get('/security.txt')->assertRedirect('/.well-known/security.txt')->assertStatus(301);
});

it('lets an HQ admin set the contact, validated, and audits that it changed but not what it is', function () {
    $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    $this->actingAs($admin);
    session()->put('hq.step_up', ['user_id' => $admin->id, 'at' => now()->getTimestamp()]);

    Livewire::test(Settings::class)
        ->set('securityContact', 'http://osakert.example.test')
        ->call('save', null)
        ->assertHasErrors('security_contact');

    Livewire::test(Settings::class)
        ->set('securityContact', 'kontakt@example.test')
        ->call('save', null)
        ->assertHasNoErrors();

    InstanceSettings::current()->forget();
    $audit = AuditLog::query()->where('action', 'hq.settings_updated')->latest('id')->firstOrFail();

    expect(InstanceSettings::current()->securityContact())->toBe('kontakt@example.test')
        ->and(json_encode($audit->payload))->not->toContain('kontakt@example.test')
        ->and($audit->payload['after'])->toEqual([InstanceSettings::SECURITY_CONTACT => true]);

    Livewire::test(Settings::class)->set('securityContact', '')->call('save', null)->assertHasNoErrors();
    InstanceSettings::current()->forget();

    expect(InstanceSettings::current()->securityContact())->toBeNull();
});

it('leaves the contact alone when the action is called without it', function () {
    $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    contactSetting('kvar@example.test');

    app(UpdateInstanceSettings::class)($admin, ['waitlist_open' => false, 'registration_open' => false]);
    InstanceSettings::current()->forget();

    expect(InstanceSettings::current()->securityContact())->toBe('kvar@example.test');

    expect(fn () => app(UpdateInstanceSettings::class)($admin, ['waitlist_open' => true, 'registration_open' => false, 'security_contact' => 'inte en adress']))
        ->toThrow(ValidationException::class);
});

it('has no policy page until it is published', function () {
    config(['app.landing.security_email' => 'sakerhet@example.test', 'app.landing.hosted' => true]);

    $this->get('/sakerhetspolicy')->assertNotFound();
    $this->get('/')->assertOk()->assertDontSee('/sakerhetspolicy', false);
});

it('renders the Swedish security policy of the hosted service with the waiver and the contact', function () {
    config(['app.landing.security_email' => 'sakerhet@example.test', 'app.landing.hosted' => true, 'app.landing.security_policy_published' => true]);

    expect(route('security.policy', [], false))->toBe('/sakerhetspolicy');

    $html = $this->get('/sakerhetspolicy')->assertOk()
        ->assertSee('Säkerhetspolicy och ansvarsfull rapportering')
        ->assertSee('sakerhet@example.test')
        ->assertSee('Så rapporterar du')
        ->assertSee('Skydd för dig som rapporterar')
        ->assertSee('Ser du andras uppgifter: sluta direkt, spara, kopiera eller ändra inget och berätta för oss.')
        ->assertSee('Report a vulnerability')
        ->assertSee('Vad som omfattas')
        ->assertSee('Omfattas inte')
        ->assertSee('href="'.route('legal.privacy').'"', false)
        ->assertSee(SecurityTxt::baseUrl().'/status', false)
        ->assertSee('/.well-known/security.txt', false)
        ->assertDontSee('det minsta som behövs')
        ->getContent();

    expect($html)->not->toMatch('/\{(foretag|orgnr|sakerhetskontakt|sakerhetstxt|status|egendrift|sakerhet|version|integritet)\}/')
        ->and($html)->not->toContain('hosted:start');
});

it('gives a self-hosted installation a neutral policy without the waiver, and only with a company name', function () {
    config(['app.landing.security_email' => 'sakerhet@example.test', 'app.landing.hosted' => false, 'app.landing.security_policy_published' => true, 'app.landing.company_name' => null]);

    $this->get('/sakerhetspolicy')->assertNotFound();

    config(['app.landing.company_name' => 'Min Firma AB']);

    $this->get('/sakerhetspolicy')->assertOk()
        ->assertSee('Om testning')
        ->assertSee('Min Firma AB')
        ->assertDontSee('Skydd för dig som rapporterar')
        ->assertDontSee('inleder inga rättsliga åtgärder')
        ->assertDontSee('Report a vulnerability')
        ->assertDontSee('polisanmälan');

    config(['app.landing.security_policy_published' => false]);

    $this->get('/sakerhetspolicy')->assertNotFound();
});

it('tells a reporter how the report is kept and links the privacy policy', function () {
    $markdown = (string) file_get_contents(base_path('docs/juridik/sakerhetspolicy.md'));

    expect($markdown)->toContain('i högst två år efter att ärendet avslutades')
        ->and($markdown)->toContain('[integritetspolicyn]({integritet})')
        ->and((string) file_get_contents(base_path('docs/gdpr/registerforteckning.md')))->toContain('Säkerhetsrapporter')
        ->and((string) file_get_contents(base_path('docs/gdpr/lagringstider.md')))->toContain('Säkerhetsrapporter');
});

it('lets an HQ admin confirm the security contact, which renews Expires, and warns before it runs out', function () {
    config(['app.landing.security_email' => 'miljo@example.test']);
    $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    $this->actingAs($admin);
    session()->put('hq.step_up', ['user_id' => $admin->id, 'at' => now()->getTimestamp()]);

    Livewire::test(Settings::class)->assertSee('security.txt gäller till');

    contactSetting('gammal@example.test');
    InstanceSetting::query()->whereKey(InstanceSettings::SECURITY_CONTACT)->update(['updated_at' => now()->subDays(350)]);

    Livewire::test(Settings::class)->assertSee('går ut')->assertSee('Bekräfta säkerhetskontakten');

    Livewire::test(Settings::class)->call('confirmSecurityContact', null)->assertHasNoErrors()->assertSee('security.txt gäller till');

    $row = InstanceSetting::query()->findOrFail(InstanceSettings::SECURITY_CONTACT);
    $audit = AuditLog::query()->where('action', 'hq.security_contact_confirmed')->sole();

    expect($row->updated_at->greaterThan(now()->subMinute()))->toBeTrue()
        ->and($row->value)->toBe('gammal@example.test')
        ->and($audit->payload)->toBe([])
        ->and(SecurityTxt::expiresSoon())->toBeFalse();
});

it('refuses to confirm a contact that does not exist, and needs the passkey step', function () {
    $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    $this->actingAs($admin);

    Livewire::test(Settings::class)->call('confirmSecurityContact', null)->assertHasErrors('passkey');

    session()->put('hq.step_up', ['user_id' => $admin->id, 'at' => now()->getTimestamp()]);

    Livewire::test(Settings::class)->call('confirmSecurityContact', null)->assertHasErrors('security_contact');
});

it('keeps the safe harbour to what the operator can promise itself', function () {
    $markdown = (string) file_get_contents(base_path('docs/juridik/sakerhetspolicy.md'));

    expect($markdown)->toContain('Det här gäller bara vad vi själva gör')
        ->and($markdown)->toContain('inget tillstånd att göra något som är olagligt')
        ->and($markdown)->toContain('TODO(legal-verify)');
});
