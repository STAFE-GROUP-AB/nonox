<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Banking\Actions\ConnectBank;
use App\Modules\Banking\Actions\FetchBankData;
use App\Modules\Banking\Actions\RevokeBankConnection;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Http\BankAuthController;
use App\Modules\Banking\Jobs\FetchBankDataJob;
use App\Modules\Banking\Livewire\BankingPage;
use App\Modules\Banking\Mail\BankConsentExpiringMail;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Models\BankFeedTransaction;
use App\Modules\Banking\Providers\FakeBankDataProvider;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Banking\Support\BankingTodos;
use App\Modules\Banking\Support\ProviderFailed;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Banking;
use Tests\Support\Bookkeeping;

/*
 * Launch hardening of NONOX Banking (ADR-034, addendum 2026-11-06): the
 * operator's kill switch, retries with growing pauses when the provider is
 * down, consents that run out, and Swedish texts for what a user meets.
 * Only the fake bank is used; nothing reaches a provider.
 */

beforeEach(function () {
    FakeBankDataProvider::reset();
    $this->travelTo(CarbonImmutable::parse('2026-03-22 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Banking::enable($this->company, $this->owner);
});

afterEach(fn () => FakeBankDataProvider::reset());

describe('the kill switch', function () {
    it('pauses every provider with true, or the ones named in a list', function () {
        expect(BankDataProviders::isPaused())->toBeFalse();

        config(['services.banking.paused' => 'enable_banking']);
        expect(BankDataProviders::isPaused())->toBeFalse()
            ->and(BankDataProviders::isPaused('enable_banking'))->toBeTrue();

        config(['services.banking.paused' => 'fake, enable_banking']);
        expect(BankDataProviders::isPaused())->toBeTrue();

        config(['services.banking.paused' => 'true']);
        expect(BankDataProviders::isPaused('enable_banking'))->toBeTrue();

        config(['services.banking.paused' => '']);
        expect(BankDataProviders::isPaused())->toBeFalse();
    });

    it('stops fetching and connecting at once, keeps the data and says so in Swedish', function () {
        Queue::fake();
        $connection = Banking::connect($this->owner, $this->company);
        Banking::mapped($this->owner, $this->company, $connection);
        config(['services.banking.paused' => true]);

        $result = app(FetchBankData::class)($this->company, $connection->fresh());
        $this->artisan('bank:fetch')->assertSuccessful();

        expect($result->error)->toBe('paused')
            ->and($connection->fresh()->last_error)->toBeNull()
            ->and($connection->fresh()->status)->toBe(ConnectionStatus::Active)
            ->and(AuditLog::query()->where('action', 'banking.fetch_failed')->exists())->toBeFalse();
        Queue::assertNothingPushed();

        expect(fn () => app(ConnectBank::class)->start($this->owner, $this->company, 'Demobanken', 'business', 'https://nonox.test/bank/klar'))
            ->toThrow(ValidationException::class, 'tillfälligt avstängd');

        Livewire::test(BankingPage::class)
            ->assertSee(BankDataProviders::pausedMessage())
            ->assertDontSee('Koppla en bank')
            ->assertDontSeeHtml('wire:click="fetch(')
            ->assertDontSeeHtml('wire:click="startConnecting"')
            ->call('fetch', $connection->id)
            ->assertDispatched('toast', message: BankDataProviders::pausedMessage());

        expect(app(ModuleManager::class)->enableCheck(BankingModule::KEY, Bookkeeping::company()[1])->blockers[0] ?? '')->toContain('tillfälligt avstängd');
    });

    it('lets a company disconnect while paused, without calling the provider', function () {
        $connection = Banking::connect($this->owner, $this->company);
        config(['services.banking.paused' => true]);

        app(RevokeBankConnection::class)($this->owner, $this->company, $connection);

        expect(FakeBankDataProvider::$revoked)->toBe([])
            ->and($connection->fresh()->status)->toBe(ConnectionStatus::Revoked)
            ->and($connection->fresh()->session_id)->toBeNull()
            ->and(AuditLog::query()->where('action', 'banking.revoked')->sole()->payload['closed_at_provider'])->toBeFalse();
    });

    it('finishes no consent that comes back from the bank while paused', function () {
        ['flow' => $flow] = app(ConnectBank::class)->start($this->owner, $this->company, 'Demobanken', 'business', route('banking.callback'));
        config(['services.banking.paused' => true]);

        $this->withSession([BankAuthController::SESSION => $flow])
            ->get(route('banking.callback', ['code' => 'fake-2026-09-18-x', 'state' => $flow['state']]))
            ->assertRedirect(route('banking.index'));

        expect(BankConnection::query()->count())->toBe(0)
            ->and(collect(session('toasts', []))->pluck('message')->all())->toBe([BankDataProviders::pausedMessage()]);
    });

    it('sends no renewal reminder while paused, and sends it once the provider is back', function () {
        Mail::fake();
        Banking::connect($this->owner, $this->company, '2026-04-01');
        config(['services.banking.paused' => true]);

        $this->artisan('bank:remind-consent')->assertSuccessful();
        Mail::assertNothingSent();

        config(['services.banking.paused' => false]);
        $this->artisan('bank:remind-consent')->assertSuccessful();
        Mail::assertSent(BankConsentExpiringMail::class, 1);
    });
});

describe('a provider that fails', function () {
    it('backs off with growing pauses and never stores a transaction twice', function () {
        Queue::fake();
        $connection = Banking::connect($this->owner, $this->company);
        Banking::mapped($this->owner, $this->company, $connection);
        FakeBankDataProvider::$transactions['1'] = [Banking::transaction('2026-03-20', 50_000, id: 'TX-1')];
        FakeBankDataProvider::$failures = [new ProviderFailed('Enable Banking answered 503.', retryable: true), new ProviderFailed('Enable Banking answered 503.', retryable: true)];

        $first = app(FetchBankData::class)($this->company, $connection->fresh());

        expect($first->error)->not->toBeNull()
            ->and($first->retryable)->toBeTrue()
            ->and($connection->fresh()->consecutive_failures)->toBe(1)
            ->and($connection->fresh()->next_attempt_at?->toDateTimeString())->toBe('2026-03-22 10:15:00');

        // The scheduler leaves it alone until the pause is over.
        $this->artisan('bank:fetch')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travel(16)->minutes();
        app(FetchBankData::class)($this->company, $connection->fresh());
        expect($connection->fresh()->consecutive_failures)->toBe(2)
            ->and($connection->fresh()->next_attempt_at?->toDateTimeString())->toBe('2026-03-22 11:16:00');

        $this->travel(61)->minutes();
        $this->artisan('bank:fetch')->assertSuccessful();
        Queue::assertPushed(FetchBankDataJob::class, 1);

        $ok = app(FetchBankData::class)($this->company, $connection->fresh());
        app(FetchBankData::class)($this->company, $connection->fresh());

        expect($ok->new)->toBe(1)
            ->and(BankFeedTransaction::query()->count())->toBe(1)
            ->and($connection->fresh()->consecutive_failures)->toBe(0)
            ->and($connection->fresh()->next_attempt_at)->toBeNull()
            ->and($connection->fresh()->last_error)->toBeNull();
    });

    it('retries a queued fetch after the pause when the provider may come back, and not when it refused', function () {
        $connection = Banking::connect($this->owner, $this->company);
        Banking::mapped($this->owner, $this->company, $connection);

        FakeBankDataProvider::$failures = [new ProviderFailed('Enable Banking could not be reached.', retryable: true)];
        $job = (new FetchBankDataJob($this->company->id, $connection->id))->withFakeQueueInteractions();
        $job->handle(app(FetchBankData::class), app(ModuleManager::class));
        $job->assertReleased(delay: 900);

        FakeBankDataProvider::$failures = [new ProviderFailed('Enable Banking answered 400 (WRONG_REQUEST_PARAMETERS).')];
        $connection->fresh()?->forceFill(['next_attempt_at' => null])->save();
        $refused = (new FetchBankDataJob($this->company->id, $connection->id))->withFakeQueueInteractions();
        $refused->handle(app(FetchBankData::class), app(ModuleManager::class));
        $refused->assertNotReleased();
    });

    it('logs a failure with ids only and tells the managers on Översikt when it keeps failing', function () {
        Log::spy();
        $connection = Banking::connect($this->owner, $this->company);
        Banking::mapped($this->owner, $this->company, $connection);

        foreach (range(1, 3) as $attempt) {
            FakeBankDataProvider::$failures = [new ProviderFailed('Enable Banking answered 503.', retryable: true)];
            app(FetchBankData::class)($this->company, $connection->fresh());
            // Each try after its pause: during the pause the bank is not asked.
            $this->travelTo($connection->fresh()->next_attempt_at);
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Banking')
            && array_keys($context) === ['company_id', 'connection_id', 'provider', 'retryable', 'failures']
            && ! str_contains((string) json_encode($context), (string) $connection->session_id));

        $titles = collect(app(BankingTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today()))->pluck('title');
        expect($titles)->toContain('Hämtningen från Demobanken fungerar inte');

        Livewire::test(BankingPage::class)->assertSee('Banken har inte gått att nå de senaste 3 gångerna');
    });
});

describe('the pause after a failure', function () {
    it('asks the bank nothing while a connection waits out its pause, from a queued job or the scheduler', function () {
        $connection = Banking::connect($this->owner, $this->company);
        Banking::mapped($this->owner, $this->company, $connection);
        FakeBankDataProvider::$transactions['1'] = [Banking::transaction('2026-03-20', 50_000, id: 'TX-1')];
        FakeBankDataProvider::$failures = [new ProviderFailed('Enable Banking answered 503.', retryable: true)];
        app(FetchBankData::class)($this->company, $connection->fresh());

        // A job queued before the failure (backlog) runs during the pause: no call, no extra failure.
        FakeBankDataProvider::$failures = [new ProviderFailed('Enable Banking answered 503.', retryable: true)];
        $backlog = (new FetchBankDataJob($this->company->id, $connection->id))->withFakeQueueInteractions();
        $backlog->handle(app(FetchBankData::class), app(ModuleManager::class));
        $result = app(FetchBankData::class)($this->company, $connection->fresh());

        expect($result->error)->toBe('backoff')
            ->and(FakeBankDataProvider::$failures)->toHaveCount(1)
            ->and($connection->fresh()->consecutive_failures)->toBe(1)
            ->and(BankFeedTransaction::query()->count())->toBe(0);
        $backlog->assertNotReleased();

        // When the pause is over the released retry runs.
        FakeBankDataProvider::$failures = [];
        $this->travel(15)->minutes();
        expect(app(FetchBankData::class)($this->company, $connection->fresh())->new)->toBe(1);
    });

    it('refuses Hämta nu within a quarter of an hour of a failed attempt, and allows it after', function () {
        $connection = Banking::connect($this->owner, $this->company);
        Banking::mapped($this->owner, $this->company, $connection);
        FakeBankDataProvider::$transactions['1'] = [];

        FakeBankDataProvider::$failures = [new ProviderFailed('Enable Banking answered 503.', retryable: true)];
        app(FetchBankData::class)($this->company, $connection->fresh());
        $this->travelTo($connection->fresh()->next_attempt_at);
        FakeBankDataProvider::$failures = [new ProviderFailed('Enable Banking answered 503.', retryable: true)];
        app(FetchBankData::class)($this->company, $connection->fresh());

        // Two failures: the next scheduled try is an hour away, the last attempt was just now.
        Livewire::test(BankingPage::class)->call('fetch', $connection->id)->assertDispatched('toast', message: 'Banken hämtades nyss. Försök igen om en kvart.');
        expect($connection->fresh()->consecutive_failures)->toBe(2);

        $this->travel(16)->minutes();
        Livewire::test(BankingPage::class)->call('fetch', $connection->id)->assertDispatched('toast', message: '0 nya transaktioner hämtades från Demobanken.');
        expect($connection->fresh()->consecutive_failures)->toBe(0);
    });
});

describe('a consent that runs out', function () {
    it('is marked as expired when its end has passed, without asking the bank, and is not fetched', function () {
        Queue::fake();
        $connection = Banking::connect($this->owner, $this->company, '2026-03-25');
        $this->travelTo(CarbonImmutable::parse('2026-03-26 08:00:00'));

        $this->artisan('bank:fetch')->assertSuccessful();

        Queue::assertNothingPushed();
        $audit = AuditLog::query()->where('action', 'banking.consent_ended')->sole();
        expect($connection->fresh()->status)->toBe(ConnectionStatus::Expired)
            ->and($connection->fresh()->session_id)->toBeNull()
            ->and($audit->payload['reason'])->toBe('valid_until')
            ->and(collect(app(BankingTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today()))->pluck('title'))->toContain('Koppla Demobanken igen');
    });

    it('is also expired by the daily reminder run', function () {
        $connection = Banking::connect($this->owner, $this->company, '2026-03-25');
        $this->travelTo(CarbonImmutable::parse('2026-03-26 07:10:00'));

        $this->artisan('bank:remind-consent')->assertSuccessful();

        expect($connection->fresh()->status)->toBe(ConnectionStatus::Expired);
    });
});
