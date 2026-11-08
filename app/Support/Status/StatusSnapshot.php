<?php

declare(strict_types=1);

namespace App\Support\Status;

use App\Domain\Status\ComponentState;
use App\Domain\Status\IncidentSeverity;
use App\Models\StatusHeartbeat;
use App\Models\StatusIncident;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Integrations\IntegrationHealth;
use App\Support\Skatteverket\Skatteverket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Everything the public status page (ADR-078) shows, as plain arrays and
 * strings so it can sit in the cache for a minute. A fixed number of
 * queries whatever the number of companies, users or incidents, and no
 * company, person, count of customers or secret ever enters it: only a
 * state, a short Swedish note and the time of the last success.
 *
 * Whatever the instance cannot know is shown as ComponentState::Unknown,
 * or left out (backups and restore tests are shown only when the operator's
 * scripts report them).
 *
 * @phpstan-type Component array{key: string, name: string, state: string, note: string, last: ?string}
 * @phpstan-type Update array{status: string, body: string, at: string}
 * @phpstan-type Incident array{id: int, title: string, severity: string, severity_label: string, status: string, status_label: string, open: bool, started: string, resolved: ?string, updates: list<Update>}
 */
final class StatusSnapshot
{
    public const string CACHE_KEY = 'status.snapshot.v1';

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

    /**
     * @return array{
     *     generated: string,
     *     overall: string,
     *     services: list<Component>,
     *     external: list<Component>,
     *     incidents: list<Incident>
     * }
     */
    public static function get(): array
    {
        /** @var array{generated: string, overall: string, services: list<Component>, external: list<Component>, incidents: list<Incident>} */
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => (new self)->build());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{generated: string, overall: string, services: list<Component>, external: list<Component>, incidents: list<Incident>}
     */
    public function build(): array
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            return [
                'generated' => self::time(CarbonImmutable::now()),
                'overall' => ComponentState::Outage->value,
                'services' => [self::component('app', 'Webbappen', ComponentState::Degraded, 'Svarar, men databasen gör det inte.'), self::component('database', 'Databas', ComponentState::Outage, 'Databasen svarar inte.')],
                'external' => [],
                'incidents' => [],
            ];
        }

        $heartbeats = StatusHeartbeat::query()->get()->keyBy('key');
        $services = [
            self::component('app', 'Webbappen', ComponentState::Ok, 'Svarar.'),
            self::component('database', 'Databas', ComponentState::Ok, 'Svarar.'),
            $this->queue(),
            $this->job($heartbeats, Heartbeats::SCHEDULER, 'Schemalagda jobb', 'Körs enligt schema.', 'Schemat har inte körts som planerat.'),
            $this->job($heartbeats, Heartbeats::INTEGRITY, 'Nattlig integritetskontroll av bokföringen', 'Senaste kontrollen visade inga avvikelser.', 'Kontrollen rapporterade avvikelser och utreds.'),
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

            if ($driver !== 'database') {
                return self::component('queue', 'Bakgrundsjobb', ComponentState::Unknown, 'Kön mäts inte på den här installationen.');
            }

            $table = (string) config("queue.connections.{$connection}.table", 'jobs');

            if (! Schema::hasTable($table)) {
                return self::component('queue', 'Bakgrundsjobb', ComponentState::Unknown, 'Kön mäts inte på den här installationen.');
            }

            $oldest = DB::table($table)->whereNull('reserved_at')->where('available_at', '<=', now()->getTimestamp())->min('available_at');
            $minutes = $oldest === null ? 0 : (int) floor((now()->getTimestamp() - (int) $oldest) / 60);

            return match (true) {
                $minutes >= self::QUEUE_STUCK_MINUTES => self::component('queue', 'Bakgrundsjobb', ComponentState::Outage, 'Jobb står stilla i kön. Sådant som utskick och synkningar är försenat.'),
                $minutes >= self::QUEUE_LATE_MINUTES => self::component('queue', 'Bakgrundsjobb', ComponentState::Degraded, 'Kön är försenad. Sådant som utskick och synkningar tar längre tid än vanligt.'),
                default => self::component('queue', 'Bakgrundsjobb', ComponentState::Ok, 'Kön töms i takt.'),
            };
        } catch (Throwable) {
            return self::component('queue', 'Bakgrundsjobb', ComponentState::Unknown, 'Kunde inte mätas just nu.');
        }
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
        try {
            $health = (new IntegrationHealth)();
        } catch (Throwable) {
            $health = null;
        }

        $bank = $this->provider($heartbeats, Heartbeats::BANK, 'bank', 'Bankkoppling', BankDataProviders::isConfigured(), BankDataProviders::isPaused(), $health === null ? null : $health['banking']['problems'] === []);
        $peppol = $this->provider($heartbeats, Heartbeats::PEPPOL, 'peppol', 'Peppol (e-faktura)', AccessPoints::isConfigured(), AccessPoints::isPaused(), $health === null ? null : $health['peppol']['problems'] === []);
        $skatteverket = Skatteverket::isConfigured()
            ? self::component('skatteverket', 'Skatteverket', ComponentState::Unknown, 'Inställd. Anslutningen mäts inte.')
            : self::component('skatteverket', 'Skatteverket', ComponentState::Unknown, 'Inte inställd på den här installationen.');

        return [$peppol, $bank, $skatteverket];
    }

    /**
     * @param  Collection<string, StatusHeartbeat>  $heartbeats
     * @return Component
     */
    private function provider(Collection $heartbeats, string $heartbeat, string $key, string $name, bool $configured, bool $paused, ?bool $healthy): array
    {
        if (! $configured) {
            return self::component($key, $name, ComponentState::Unknown, 'Inte inställd på den här installationen.');
        }

        if ($paused) {
            return self::component($key, $name, ComponentState::Maintenance, 'Pausad av driften. Inget skickas eller hämtas under tiden.');
        }

        $job = $this->job($heartbeats, $heartbeat, $name, 'Inställd. Senaste synkningen lyckades.', 'Senaste synkningen misslyckades.');
        $last = $job['last'];

        if ($healthy === false) {
            return self::component($key, $name, ComponentState::Degraded, 'Störningar i anslutningen. Driften utreder.', $last === null ? null : CarbonImmutable::parse($last, config('app.timezone')));
        }

        if ($job['state'] === ComponentState::Unknown->value) {
            return self::component($key, $name, ComponentState::Unknown, 'Inställd. Ingen synkning är registrerad ännu.');
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
