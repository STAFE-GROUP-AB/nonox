<?php

declare(strict_types=1);

namespace App\Support\Status;

use App\Console\Commands\VerifyLedgerCommand;
use App\Domain\Status\ComponentState;
use App\Domain\Status\IncidentSeverity;
use App\Models\StatusHeartbeat;
use App\Models\StatusIncident;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Everything the public status page (ADR-078) shows, as plain arrays and
 * strings so it can sit in the cache for a minute. A fixed number of
 * queries whatever the number of companies, users or incidents, and no
 * company, person, count of customers or secret ever enters it: only a
 * state, a short Swedish note and the time of the last success.
 *
 * Only global signals decide the state of a connection: the job's own
 * heartbeat, the pause switch, and a failing share of at least a quarter of
 * at least three connections. What is wrong for one company stays in /hq.
 * The integrity check is shown as "ran" or "did not run"; what it found
 * stays in /hq and in the operator's log.
 *
 * Whatever the instance cannot know is shown as ComponentState::Unknown,
 * or left out (backups and restore tests are shown only when the operator's
 * scripts report them).
 *
 * @phpstan-type Component array{key: string, name: string, state: string, note: string, last: ?string}
 * @phpstan-type Update array{status: string, body: string, at: string}
 * @phpstan-type Incident array{id: int, title: string, severity: string, severity_label: string, status: string, status_label: string, open: bool, started: string, resolved: ?string, updates: list<Update>}
 * @phpstan-type Snapshot array{generated: string, overall: string, services: list<Component>, external: list<Component>, incidents: list<Incident>}
 */
final class StatusSnapshot
{
    public const string CACHE_KEY = 'status.snapshot.v2';

    public const int CACHE_SECONDS = 60;

    /** Minutes without a run before a job counts as late. */
    private const array LATE_AFTER = [
        Heartbeats::SCHEDULER => 10,
        Heartbeats::INTEGRITY => 36 * 60,
        Heartbeats::PEPPOL => 120,
        Heartbeats::BANK => 180,
        Heartbeats::BACKUP => 36 * 60,
        Heartbeats::RESTORE_TEST => 9 * 24 * 60,
    ];

    private const int QUEUE_LATE_MINUTES = 10;

    private const int QUEUE_STUCK_MINUTES = 60;

    /** A connection counts as disrupted for everyone when this share of at least MIN_CONNECTIONS fails. */
    private const float FAILING_SHARE = 0.25;

    private const int MIN_CONNECTIONS = 3;

    /** @return Snapshot */
    public static function get(): array
    {
        try {
            $hit = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return self::fresh();
        }

        if (is_array($hit)) {
            /** @var Snapshot $hit */
            return $hit;
        }

        try {
            // One request rebuilds; the others wait for it instead of all asking the database at once.
            return Cache::lock(self::CACHE_KEY.'.lock', 10)->block(5, function (): array {
                $hit = Cache::get(self::CACHE_KEY);

                if (is_array($hit)) {
                    /** @var Snapshot $hit */
                    return $hit;
                }

                $built = (new self)->build();

                if ($built === null) {
                    return self::fallback();
                }

                Cache::put(self::CACHE_KEY, $built, self::CACHE_SECONDS);

                return $built;
            });
        } catch (Throwable) {
            return self::fresh();
        }
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Nothing cached that could be forgotten.
        }
    }

    /**
     * Built without the cache, used when the cache itself fails.
     *
     * @return Snapshot
     */
    private static function fresh(): array
    {
        try {
            return (new self)->build() ?? self::fallback();
        } catch (Throwable) {
            return self::fallback();
        }
    }

    /**
     * What the page shows when the database does not answer. Never cached.
     *
     * @return Snapshot
     */
    public static function fallback(): array
    {
        return [
            'generated' => self::time(CarbonImmutable::now()),
            'overall' => ComponentState::Outage->value,
            'services' => [
                self::component('app', 'Webbappen', ComponentState::Degraded, 'Svarar, men databasen gör det inte.'),
                self::component('database', 'Databas', ComponentState::Outage, 'Databasen svarar inte.'),
            ],
            'external' => [],
            'incidents' => [],
        ];
    }

    /** @return ?Snapshot null when the database does not answer */
    public function build(): ?array
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            return null;
        }

        $heartbeats = StatusHeartbeat::query()->get()->keyBy('key');
        $services = [
            self::component('app', 'Webbappen', ComponentState::Ok, 'Svarar.'),
            self::component('database', 'Databas', ComponentState::Ok, 'Svarar.'),
            $this->queue(),
            $this->job($heartbeats, Heartbeats::SCHEDULER, 'Schemalagda jobb', 'Körs enligt schema.', 'Schemat har inte körts som planerat.'),
            $this->integrity($heartbeats),
        ];

        foreach ([Heartbeats::BACKUP => 'Säkerhetskopiering', Heartbeats::RESTORE_TEST => 'Återställningstest av säkerhetskopian'] as $key => $name) {
            if ($heartbeats->has($key)) {
                $services[] = $this->job($heartbeats, $key, $name, 'Senaste körningen lyckades.', 'Senaste körningen misslyckades.');
            }
        }

        $external = $this->external($heartbeats);
        $incidents = $this->incidents();

        $overall = ComponentState::Ok;

        foreach ([...$services, ...$external] as $component) {
            $overall = $overall->worst(ComponentState::from($component['state']));
        }

        foreach ($incidents as $incident) {
            $state = $incident['open'] ? IncidentSeverity::from($incident['severity'])->state() : null;
            $overall = $state === null ? $overall : $overall->worst($state);
        }

        return [
            'generated' => self::time(CarbonImmutable::now()),
            'overall' => $overall->value,
            'services' => $services,
            'external' => $external,
            'incidents' => $incidents,
        ];
    }

    /** @return Component */
    private function queue(): array
    {
        try {
            $connection = (string) config('queue.default', 'sync');
            $driver = (string) config("queue.connections.{$connection}.driver", $connection);

            if ($driver === 'sync' || $driver === 'null') {
                return self::component('queue', 'Bakgrundsjobb', ComponentState::Ok, 'Körs direkt, ingen kö.');
            }

            $oldest = Queue::connection($connection)->creationTimeOfOldestPendingJob();
            $minutes = is_numeric($oldest) ? max(0, (int) floor((now()->getTimestamp() - (int) $oldest) / 60)) : 0;

            return match (true) {
                $minutes >= self::QUEUE_STUCK_MINUTES => self::component('queue', 'Bakgrundsjobb', ComponentState::Outage, 'Jobb står stilla i kön. Sådant som utskick och synkningar är försenat.'),
                $minutes >= self::QUEUE_LATE_MINUTES => self::component('queue', 'Bakgrundsjobb', ComponentState::Degraded, 'Kön är försenad. Sådant som utskick och synkningar tar längre tid än vanligt.'),
                default => self::component('queue', 'Bakgrundsjobb', ComponentState::Ok, 'Kön töms i takt.'),
            };
        } catch (Throwable) {
            return self::component('queue', 'Bakgrundsjobb', ComponentState::Unknown, 'Kön mäts inte på den här installationen.');
        }
    }

    /**
     * The nightly integrity check: "ran" or "did not run", with the time.
     * What it found is for /hq and the log, not for this page.
     *
     * @param  Collection<string, StatusHeartbeat>  $heartbeats
     * @return Component
     */
    private function integrity(Collection $heartbeats): array
    {
        $name = 'Nattlig integritetskontroll av bokföringen';
        $beat = $heartbeats->get(Heartbeats::INTEGRITY);

        if (! $beat instanceof StatusHeartbeat || $beat->last_completed_at === null && $beat->last_failure_at === null) {
            return self::component(Heartbeats::INTEGRITY, $name, ComponentState::Unknown, 'Ingen körning är registrerad ännu.');
        }

        $completed = $beat->last_completed_at;

        if ($completed === null || ($beat->last_failure_at !== null && $beat->last_failure_at->greaterThan($completed) && $beat->last_exit_code !== null && ! self::completedCode($beat->last_exit_code))) {
            return self::component(Heartbeats::INTEGRITY, $name, ComponentState::Degraded, 'Senaste körningen kunde inte genomföras.', $completed);
        }

        if ($completed->lessThan(CarbonImmutable::now()->subMinutes(self::LATE_AFTER[Heartbeats::INTEGRITY]))) {
            return self::component(Heartbeats::INTEGRITY, $name, ComponentState::Degraded, 'Har inte körts som planerat.', $completed);
        }

        return self::component(Heartbeats::INTEGRITY, $name, ComponentState::Ok, 'Kontrollen har körts.', $completed);
    }

    private static function completedCode(int $code): bool
    {
        return $code === 0 || $code === VerifyLedgerCommand::EXIT_DEVIATIONS;
    }

    /**
     * @param  Collection<string, StatusHeartbeat>  $heartbeats
     * @return Component
     */
    private function job(Collection $heartbeats, string $key, string $name, string $okNote, string $badNote): array
    {
        $beat = $heartbeats->get($key);

        if (! $beat instanceof StatusHeartbeat) {
            return self::component($key, $name, ComponentState::Unknown, 'Ingen körning är registrerad ännu.');
        }

        $last = $beat->last_success_at;

        if ($beat->last_failure_at !== null && ($last === null || $beat->last_failure_at->greaterThan($last))) {
            return self::component($key, $name, ComponentState::Degraded, $badNote, $last);
        }

        if ($last === null || $last->lessThan(CarbonImmutable::now()->subMinutes(self::LATE_AFTER[$key] ?? 1440))) {
            return self::component($key, $name, ComponentState::Degraded, 'Har inte körts som planerat.', $last);
        }

        return self::component($key, $name, ComponentState::Ok, $okNote, $last);
    }

    /**
     * @param  Collection<string, StatusHeartbeat>  $heartbeats
     * @return list<Component>
     */
    private function external(Collection $heartbeats): array
    {
        [$bank, $peppol] = $this->widespread();

        $skatteverket = Skatteverket::isConfigured()
            ? self::component('skatteverket', 'Skatteverket', ComponentState::Unknown, 'Inställd. Anslutningen mäts inte.')
            : self::component('skatteverket', 'Skatteverket', ComponentState::Unknown, 'Inte inställd på den här installationen.');

        return [
            $this->provider($heartbeats, Heartbeats::PEPPOL, 'peppol', 'Peppol (e-faktura)', AccessPoints::isConfigured(), AccessPoints::isPaused(), $peppol),
            $this->provider($heartbeats, Heartbeats::BANK, 'bank', 'Bankkoppling', BankDataProviders::isConfigured(), BankDataProviders::isPaused(), $bank),
            $skatteverket,
        ];
    }

    /**
     * Whether a failure is widespread for bank and Peppol: a quarter or more of at least three connections.
     * Two counting queries across companies; nothing about a single company leaves them.
     *
     * @return array{0: bool, 1: bool} [bank, peppol]
     */
    private function widespread(): array
    {
        try {
            return CompanyScope::bypass(function (): array {
                $bank = BankConnection::query()
                    ->where('status', ConnectionStatus::Active->value)
                    ->selectRaw('count(*) as total')
                    ->selectRaw('count(*) filter (where consecutive_failures >= ?) as bad', [BankConnection::FAILURES_TO_TELL])
                    ->toBase()
                    ->first();
                $peppol = PeppolParticipant::query()
                    ->whereNotNull('provider_account')
                    ->selectRaw('count(*) as total')
                    ->selectRaw('count(*) filter (where last_error is not null or last_polled_at is null or last_polled_at < ?) as bad', [CarbonImmutable::now()->subHour()])
                    ->toBase()
                    ->first();

                return [self::widespreadShare((int) ($bank->total ?? 0), (int) ($bank->bad ?? 0)), self::widespreadShare((int) ($peppol->total ?? 0), (int) ($peppol->bad ?? 0))];
            });
        } catch (Throwable) {
            return [false, false];
        }
    }

    private static function widespreadShare(int $total, int $bad): bool
    {
        return $total >= self::MIN_CONNECTIONS && $bad / $total >= self::FAILING_SHARE;
    }

    /**
     * @param  Collection<string, StatusHeartbeat>  $heartbeats
     * @return Component
     */
    private function provider(Collection $heartbeats, string $heartbeat, string $key, string $name, bool $configured, bool $paused, bool $widespread): array
    {
        if (! $configured) {
            return self::component($key, $name, ComponentState::Unknown, 'Inte inställd på den här installationen.');
        }

        if ($paused) {
            return self::component($key, $name, ComponentState::Maintenance, 'Pausad av driften. Inget skickas eller hämtas under tiden.');
        }

        $job = $this->job($heartbeats, $heartbeat, $name, 'Inställd. Senaste synkningen lyckades.', 'Senaste synkningen misslyckades.');

        if ($job['state'] === ComponentState::Unknown->value) {
            return self::component($key, $name, $widespread ? ComponentState::Degraded : ComponentState::Unknown, $widespread ? 'Störningar i anslutningen. Driften utreder.' : 'Inställd. Ingen synkning är registrerad ännu.');
        }

        if ($widespread && $job['state'] === ComponentState::Ok->value) {
            return [...$job, 'key' => $key, 'name' => $name, 'state' => ComponentState::Degraded->value, 'note' => 'Störningar i anslutningen. Driften utreder.'];
        }

        return [...$job, 'key' => $key, 'name' => $name];
    }

    /** @return list<Incident> */
    private function incidents(): array
    {
        $cutoff = CarbonImmutable::now()->subDays(90);

        return array_values(StatusIncident::query()
            ->with('updates')
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $cutoff))
            ->orderByRaw('resolved_at is not null')
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (StatusIncident $incident): array => [
                'id' => $incident->id,
                'title' => $incident->title,
                'severity' => $incident->severity->value,
                'severity_label' => $incident->severity->label(),
                'status' => $incident->status->value,
                'status_label' => $incident->status->label(),
                'open' => $incident->isOpen(),
                'started' => self::time($incident->started_at),
                'resolved' => $incident->resolved_at === null ? null : self::time($incident->resolved_at),
                'updates' => array_values($incident->updates->map(fn ($update): array => ['status' => $update->status->label(), 'body' => $update->body, 'at' => self::time($update->created_at)])->all()),
            ])
            ->all());
    }

    /** @return Component */
    private static function component(string $key, string $name, ComponentState $state, string $note, ?CarbonImmutable $last = null): array
    {
        return ['key' => $key, 'name' => $name, 'state' => $state->value, 'note' => $note, 'last' => $last === null ? null : self::time($last)];
    }

    private static function time(CarbonImmutable $at): string
    {
        return $at->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i');
    }
}
