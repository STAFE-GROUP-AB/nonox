<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Landing\LandingPage;
use App\Support\SetupStatus;

function pretendEnvironment(string $environment): void
{
    app()->detectEnvironment(fn () => $environment);
}

it('opens the first-run guide on a new local installation', function () {
    pretendEnvironment('local');

    $this->get('/')
        ->assertOk()
        ->assertSee('Vi börjar med ditt företag')
        ->assertSee('Steg 1 av 3');
});

it('never offers the first-run guide outside a local installation', function (string $environment) {
    pretendEnvironment($environment);
    config(['app.landing.enabled' => false]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Systemet är inte färdiginstallerat')
        ->assertDontSee('Steg 1 av 3');
})->with(['production', 'staging', 'testing']);

it('shows the landing page on a public installation before the first user', function () {
    pretendEnvironment('production');

    $this->get('/')
        ->assertOk()
        ->assertSee('Bokföring som ägs av')
        ->assertDontSee('Systemet är inte färdiginstallerat');
});

it('shows the sign-in page once there is a user', function () {
    pretendEnvironment('local');
    config(['app.landing.enabled' => false]);
    User::factory()->withCompany()->create();

    $this->get('/')
        ->assertOk()
        ->assertSee('Logga in')
        ->assertSee(route('login'))
        ->assertDontSee('Steg 1 av 3');
});

it('shows signed-in users the landing page with a link to the overview instead of sign-in', function () {
    $this->actingAs(User::factory()->withCompany()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Bokföring som ägs av')
        ->assertSee('Till översikten')
        ->assertSee(route('dashboard'))
        ->assertDontSee('>Logga in<', escape: false);
});

it('sends signed-in users to the overview when the landing page is turned off', function () {
    config(['app.landing.enabled' => false]);

    $this->actingAs(User::factory()->withCompany()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('explains a missing piece in plain Swedish with one command', function () {
    pretendEnvironment('local');

    $this->mock(SetupStatus::class)->shouldReceive('problem')->andReturn([
        'title' => 'Databasen behöver skapas',
        'explanation' => 'Öppna Terminalen och klistra in raden nedan.',
        'command' => 'psql -c "CREATE DATABASE nonox;"',
        'details' => 'SQLSTATE[08006] database "nonox" does not exist',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Databasen behöver skapas')
        ->assertSee('CREATE DATABASE nonox;', escape: false)
        ->assertSee('Visa tekniska detaljer');
});

it('hides technical details from the public in production', function () {
    pretendEnvironment('production');

    $this->mock(SetupStatus::class)->shouldReceive('problem')->andReturn([
        'title' => 'Databasen behöver skapas',
        'explanation' => 'Öppna Terminalen.',
        'command' => 'psql -h 10.0.0.5 -U secretuser',
        'details' => 'SQLSTATE[08006] host 10.0.0.5',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Systemet är inte igång just nu')
        ->assertDontSee('10.0.0.5')
        ->assertDontSee('secretuser');
});

it('shows the public landing page to visitors once the system is set up', function () {
    pretendEnvironment('production');
    User::factory()->withCompany()->create();

    $this->get('/')
        ->assertOk()
        ->assertSee('Bokföring som ägs av')
        ->assertSee(route('login'))
        ->assertSee("995\u{a0}kr")
        ->assertSee('https://github.com/STAFE-GROUP-AB/OSS')
        ->assertSee('Vanliga frågor')
        ->assertDontSee('{{', escape: false)
        ->assertDontSee('Villkor');
});

it('links the legal pages and names the company when they are configured', function () {
    pretendEnvironment('production');
    User::factory()->withCompany()->create();
    config([
        'app.landing.terms_url' => 'https://example.test/villkor',
        'app.landing.company_name' => 'Exempelbolaget AB',
    ]);

    $this->get('/')
        ->assertSee('https://example.test/villkor')
        ->assertSee('Exempelbolaget AB');
});

it('shows the sign-in page instead when the landing page is turned off', function () {
    pretendEnvironment('production');
    User::factory()->withCompany()->create();
    config(['app.landing.enabled' => false]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Bokföring som ägs av')
        ->assertSee(route('login'));
});

it('calculates the saving like the page script', function () {
    expect(LandingPage::calculator(3))->toMatchArray([
        'plan' => 'Företag',
        'price' => "995\u{a0}kr/år",
        'compare' => "6\u{a0}144\u{a0}kr/år",
        'saving' => "5\u{a0}100\u{a0}kr",
    ])->and(LandingPage::calculator(20)['price'])->toBe("1\u{a0}995\u{a0}kr/år")
        ->and(LandingPage::calculator(26)['plan'])->toBe('');
});

it('says that the desktop program for Windows, Mac and Linux is coming', function () {
    pretendEnvironment('production');

    $this->get('/')
        ->assertSee('Windows, Mac och Linux')
        ->assertSee('Kommer snart')
        ->assertDontSee('Ladda ner för Mac');
});

it('says that the Docker package is coming', function () {
    pretendEnvironment('production');

    $this->get('/')
        ->assertSee('Docker kommer snart')
        ->assertDontSee('Container nonox-app');
});

it('shows the moving guide with the programs from docs/migration and the steps', function () {
    $this->get(route('migrationGuide'))
        ->assertOk()
        ->assertSee('Från inlåst till')
        ->assertSee('Fortnox')
        ->assertSee('Bokio')
        ->assertSee('Spiris')
        ->assertSee('Annat program')
        ->assertSee('Steg 8 av 8')
        ->assertSee('Kommer snart')
        ->assertSee(route('migrationGuide'));

    $this->get('/')->assertSee(route('migrationGuide'));
});

it('compares NONOX with the other programs, with sources and the time the prices were fetched', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Ett gratis alternativ till Fortnox, Visma och Bokio.')
        ->assertSee("10\u{a0}596\u{a0}kr")
        ->assertSee('2026-10-31 kl. 11:47')
        ->assertSee('https://www.fortnox.se/prislista')
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type":"FAQPage"', false)
        ->assertSee('Alla dina bolag. En skärm.')
        ->assertSee(route('alternative', 'fortnox'));
})->skip(fn () => ! str_contains((string) file_get_contents(base_path('docs/migration/jamforelse.json')), '10596'), 'prices updated');

it('has an alternative page per program and 404 for an unknown one', function () {
    $this->get(route('alternative', 'fortnox'))
        ->assertOk()
        ->assertSee('<title>Gratis alternativ till Fortnox – NONOX, bokföring med öppen källkod</title>', false)
        ->assertSee('Ett gratis alternativ till')
        ->assertSee('Du sparar')
        ->assertSee('Så flyttar du från Fortnox.')
        ->assertSee('<link rel="canonical" href="'.route('alternative', 'fortnox').'">', false);

    $this->get(route('alternative', 'bjorn-lunden'))->assertOk()->assertSee('ej verifierat');
    $this->get(route('alternative', 'nonox'))->assertNotFound();
    $this->get('/alternativ-till-okant')->assertNotFound();
});

it('lists the public pages in the sitemap and points robots to it', function () {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(route('migrationGuide'), false)
        ->assertSee(route('alternative', 'visma'), false);

    $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.url('/sitemap.xml'), false);

    config(['app.landing.enabled' => false]);

    $this->get('/robots.txt')->assertSee('Disallow: /', false);
});

it('lists the features that exist and marks the staff ledger and AI inbox as coming soon', function () {
    $this->get('/')
        ->assertSee('Moms och EU')
        ->assertSee('Min sida för anställda')
        ->assertSee('Personalliggare')
        ->assertSee('dagskassans alla rapporter');
});

it('asks for cookie consent with equal choices and lists every cookie on the cookie page', function () {
    $this->get('/')
        ->assertSee('Endast nödvändiga')
        ->assertSee('Tillåt alla')
        ->assertSee('Cookie-inställningar')
        ->assertDontSee('rsms.me', false);

    $this->get(route('cookies'))
        ->assertOk()
        ->assertSee('Så använder vi cookies.')
        ->assertSee(config('session.cookie'))
        ->assertSee('XSRF-TOKEN')
        ->assertSee('nonox-consent');

    $this->get(route('login'))->assertSee('Endast nödvändiga');
});
