<?php

declare(strict_types=1);

use App\Domain\Company\LegalForm;
use App\Domain\Waitlist\WaitlistStatus;
use App\Livewire\Hq\CustomerCompany;
use App\Livewire\Hq\Customers;
use App\Livewire\Hq\CustomerUser;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * Kunder in /hq (ADR-038): the HQ admin reads the companies and users of the
 * whole instance, metadata only. 404 for everyone else; every visit audited
 * without personal data; the number of queries does not grow with the rows.
 */

beforeEach(function () {
    Mail::fake();
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null]);

    $this->admin = User::factory()->withCompany(fn ($factory) => $factory->state(['name' => 'HQ-adminens bolag']))->create(['email' => 'hq@example.test', 'name' => 'Ägaren']);

    [$this->ownerB, $this->companyB] = Bookkeeping::company();
    $this->companyB->forceFill(['name' => 'Bolaget B Aktiebolag'])->save();

    // Financial content and personal numbers that must never reach HQ.
    CurrentCompany::set($this->companyB);
    Bookkeeping::post($this->ownerB, $this->companyB, 123456789);
    Payroll::setUp($this->companyB, $this->ownerB);
    Payroll::employee($this->ownerB, $this->companyB);
    CurrentCompany::forget();

    $nine = '811228987';
    $this->soleTraderNumber = '811228-987'.Luhn::checkDigit($nine);
    $this->soleTrader = Company::factory()->create([
        'name' => 'Enskilda firman',
        'legal_form' => LegalForm::Ef,
        'org_number' => $this->soleTraderNumber,
        'user_id' => User::factory()->create()->id,
    ]);
});

it('shows HQ with Kö and Kunder in the sidebar of an HQ admin, whatever company is selected', function () {
    $this->actingAs($this->admin);

    $html = $this->get(route('hq.customers'))->assertOk()->getContent();

    expect($html)
        ->toContain('href="'.route('hq.waitlist').'"')
        ->toMatch('#href="'.preg_quote(route('hq.customers'), '#').'"\s+aria-current="page"#')
        ->toContain('>HQ</a>');

    // Selecting another company (the admin as a member of B) keeps HQ.
    $this->companyB->users()->attach($this->admin, ['role' => 'auditor']);
    $this->admin->fresh()->switchCompany($this->companyB);

    $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('hq.waitlist').'"', false);
});

it('hides HQ from everyone who is not an HQ admin', function () {
    $this->actingAs($this->ownerB);

    $this->get(route('dashboard'))->assertOk()
        ->assertDontSee('href="'.route('hq.waitlist').'"', false)
        ->assertDontSee('href="'.route('hq.customers').'"', false);
});

it('answers 404 on Kunder and its pages to guests and to everyone else', function () {
    $paths = ['/hq/kunder', '/hq/kunder/foretag/'.$this->companyB->id, '/hq/kunder/anvandare/'.$this->ownerB->id];

    foreach ($paths as $path) {
        $this->get($path)->assertNotFound();
    }

    $this->actingAs($this->ownerB);

    foreach ($paths as $path) {
        $this->get($path)->assertNotFound();
    }

    Livewire::actingAs($this->ownerB)->test(Customers::class)->assertNotFound();
    Livewire::actingAs($this->ownerB)->test(CustomerCompany::class, ['companyId' => $this->companyB->id])->assertNotFound();
    Livewire::actingAs($this->ownerB)->test(CustomerUser::class, ['userId' => $this->ownerB->id])->assertNotFound();
});

it('answers 404 for a company or user that does not exist', function () {
    $this->actingAs($this->admin);

    $this->get('/hq/kunder/foretag/999999999')->assertNotFound();
    $this->get('/hq/kunder/anvandare/999999999')->assertNotFound();
});

it('lists the companies and users of every company to the HQ admin', function () {
    $this->actingAs($this->admin);

    $this->get('/hq/kunder')->assertOk()
        ->assertSee('HQ-adminens bolag')
        ->assertSee('Bolaget B Aktiebolag')
        ->assertSee('Enskilda firman')
        ->assertSee($this->companyB->org_number);

    $this->get('/hq/kunder?flik=anvandare')->assertOk()
        ->assertSee($this->ownerB->email)
        ->assertSee($this->admin->email);

    $this->get('/hq/kunder/foretag/'.$this->companyB->id)->assertOk()
        ->assertSee('Bolaget B Aktiebolag')
        ->assertSee($this->ownerB->email)
        ->assertSee('Lön')
        ->assertSeeHtml('data-fact="posted-vouchers">1<');

    $this->get('/hq/kunder/anvandare/'.$this->ownerB->id)->assertOk()
        ->assertSee($this->ownerB->email)
        ->assertSee('Bolaget B Aktiebolag');
});

it('never shows amounts, vouchers, employees or personal numbers', function () {
    $this->actingAs($this->admin);

    $pages = [
        $this->get('/hq/kunder')->getContent(),
        $this->get('/hq/kunder?flik=anvandare')->getContent(),
        $this->get('/hq/kunder/foretag/'.$this->companyB->id)->getContent(),
        $this->get('/hq/kunder/foretag/'.$this->soleTrader->id)->getContent(),
        $this->get('/hq/kunder/anvandare/'.$this->ownerB->id)->getContent(),
    ];

    foreach ($pages as $html) {
        expect($html)
            ->not->toContain('567,89')
            ->not->toContain('1234567')
            ->not->toContain('900615123')
            ->not->toContain('900615-123')
            ->not->toContain('Anna Kock')
            ->not->toContain($this->soleTraderNumber)
            ->not->toContain(str_replace('-', '', $this->soleTraderNumber));
    }

    expect($pages[0])->toContain('Dolt (personnummer)');
});

it('searches and filters the companies', function () {
    $archived = Company::factory()->create(['name' => 'Gamla bolaget', 'archived_at' => now(), 'user_id' => User::factory()->create()->id]);
    $archived->forceFill(['created_at' => '2025-03-01 10:00:00'])->save();

    $component = Livewire::actingAs($this->admin)->test(Customers::class);

    $component->set('search', 'bolaget b')->assertSee('Bolaget B Aktiebolag')->assertDontSee('Enskilda firman')->assertDontSee('Gamla bolaget');
    $component->set('search', str_replace('-', '', (string) $this->companyB->org_number))->assertSee('Bolaget B Aktiebolag')->assertDontSee('Gamla bolaget');
    $component->set('search', '')->set('module', PayrollModule::KEY)->assertSee('Bolaget B Aktiebolag')->assertDontSee('HQ-adminens bolag');
    $component->set('module', '')->set('archived', 'ja')->assertSee('Gamla bolaget')->assertDontSee('Bolaget B Aktiebolag');
    $component->set('archived', 'nej')->assertDontSee('Gamla bolaget')->assertSee('Bolaget B Aktiebolag');
    $component->set('archived', '')->set('from', '2025-01-01')->set('to', '2025-12-31')->assertSee('Gamla bolaget')->assertDontSee('Bolaget B Aktiebolag');
    $component->set('from', 'nonsense')->set('to', '')->assertSee('Gamla bolaget')->assertSee('Bolaget B Aktiebolag');
    $component->set('from', '')->set('sort', 'namn')->assertSeeInOrder(['Bolaget B Aktiebolag', 'Enskilda firman', 'Gamla bolaget', 'HQ-adminens bolag']);
    $component->set('sort', 'verifikationer')->assertSeeInOrder(['Bolaget B Aktiebolag', 'Enskilda firman']);
});

it('searches and filters the users, and tells where they came from', function () {
    $fromQueue = User::factory()->create(['name' => 'Köad Person', 'email' => 'ko@example.test']);
    WaitlistEntry::factory()->create(['email' => 'ko@example.test', 'status' => WaitlistStatus::Approved, 'approved_user_id' => $fromQueue->id, 'decided_at' => now()]);
    $invited = Bookkeeping::member($this->companyB, 'bookkeeper');
    $invited->forceFill(['name' => 'Inbjuden Person'])->save();
    User::factory()->create(['name' => 'Övrig Person']);

    $component = Livewire::actingAs($this->admin)->test(Customers::class)->call('showTab', 'anvandare');

    $component->assertSee('Köad Person')->assertSee('Inbjuden Person')->assertSee('Övrig Person')->assertSee('Via kön')->assertSee('Via inbjudan');
    $component->set('source', 'ko')->assertSee('Köad Person')->assertDontSee('Inbjuden Person')->assertDontSee('Övrig Person');
    $component->set('source', 'inbjudan')->assertSee('Inbjuden Person')->assertDontSee('Köad Person')->assertDontSee('Övrig Person');
    $component->set('source', 'ovrigt')->assertSee('Övrig Person')->assertDontSee('Inbjuden Person')->assertDontSee('Köad Person');
    $component->set('source', '')->set('search', 'ko@example')->assertSee('Köad Person')->assertDontSee('Övrig Person');

    $this->actingAs($this->admin)->get('/hq/kunder/anvandare/'.$invited->id)->assertOk()
        ->assertSee('Via inbjudan')
        ->assertSee('Bolaget B Aktiebolag')
        ->assertSee('Bokförare');
});

it('shows the last sign-in from the behandlingshistorik', function () {
    $this->travelTo('2026-02-03 14:15:00');
    AuditLog::create(['company_id' => null, 'user_id' => $this->ownerB->id, 'action' => 'auth.signed_in', 'payload' => []]);
    $this->travelBack();

    $this->actingAs($this->admin)->get('/hq/kunder/anvandare/'.$this->ownerB->id)->assertOk()
        ->assertSee('2026-02-03 14:15');

    $this->get('/hq/kunder/foretag/'.$this->companyB->id)->assertOk()->assertSee('2026-02-03 14:15');
});

it('writes every visit to the audit log without personal data', function () {
    $this->actingAs($this->admin);

    $this->get('/hq/kunder')->assertOk();
    $this->get('/hq/kunder/foretag/'.$this->companyB->id)->assertOk();
    $this->get('/hq/kunder/anvandare/'.$this->ownerB->id)->assertOk();

    $entries = AuditLog::query()->whereIn('action', ['hq.customers_viewed', 'hq.company_viewed', 'hq.user_viewed'])->orderBy('id')->get();

    expect($entries->pluck('action')->all())->toBe(['hq.customers_viewed', 'hq.company_viewed', 'hq.user_viewed'])
        ->and($entries->pluck('company_id')->unique()->all())->toBe([null])
        ->and($entries->pluck('user_id')->unique()->all())->toBe([$this->admin->id])
        ->and($entries[1]->payload)->toBe(['company_id' => $this->companyB->id])
        ->and($entries[2]->payload)->toBe(['user_id' => $this->ownerB->id]);

    $payloads = json_encode($entries->pluck('payload')->all());
    expect($payloads)->not->toContain($this->ownerB->email)->not->toContain($this->ownerB->name)->not->toContain('Bolaget B');
});

it('makes the same number of queries whatever the number of companies and users', function () {
    $count = function (string $tab): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->admin)->withQueryParams(['flik' => $tab])->test(Customers::class)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $companiesBefore = $count('foretag');
    $usersBefore = $count('anvandare');

    foreach (range(1, 4) as $i) {
        [$owner, $company] = Bookkeeping::company();
        Bookkeeping::member($company, 'bookkeeper');
        app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
    }

    expect($companiesBefore)->toBeLessThan(20)
        ->and($count('foretag'))->toBe($companiesBefore)
        ->and($count('anvandare'))->toBe($usersBefore);
});
