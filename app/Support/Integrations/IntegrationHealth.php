<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Models\AuditLog;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Jobs\TransmitPeppolDocument;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;

/**
 * How the installation's outside providers are doing (ADR-034 and ADR-050,
 * addenda 2026-11-06), for the operator in /hq → Integrationer and for
 * `integrations:status` in a monitoring cron. Across companies, in a fixed
 * number of queries, and with counts only: never a company, a person, an
 * account or what a bank or supplier sent.
 */
final class IntegrationHealth
{
    /** A sending queued longer than this, or a poll older than this, is a problem. */
    public const int STUCK_MINUTES = 60;

    /**
     * @return array{
     *     banking: array{driver: string, configured: bool, paused: bool, pause_env: string, active: int, failing: int, retrying: int, expiring: int, last_fetch: ?string, problems: list<string>},
     *     peppol: array{driver: string, configured: bool, paused: bool, pause_env: string, linked: int, participant_errors: int, stale_polls: int, queued: int, stuck: int, waiting_for_pause: int, failed_week: int, events_failed_week: int, problems: list<string>}
     * }
     */
    public function __invoke(): array
    {
        return CompanyScope::bypass(fn (): array => ['banking' => $this->banking(), 'peppol' => $this->peppol()]);
    }

    /** @return array{driver: string, configured: bool, paused: bool, pause_env: string, active: int, failing: int, retrying: int, expiring: int, last_fetch: ?string, problems: list<string>} */
    private function banking(): array
    {
        $now = CarbonImmutable::now();
        $row = BankConnection::query()
            ->where('status', ConnectionStatus::Active->value)
            ->selectRaw('count(*) as active')
            ->selectRaw('count(*) filter (where consecutive_failures >= ?) as failing', [BankConnection::FAILURES_TO_TELL])
            ->selectRaw('count(*) filter (where consecutive_failures > 0) as retrying')
            ->selectRaw('count(*) filter (where valid_until is not null and valid_until <= ?) as expiring', [$now->addDays(14)])
            ->selectRaw('max(last_fetched_at) as last_fetch')
            ->toBase()
            ->first();

        $paused = BankDataProviders::isPaused();
        $configured = BankDataProviders::isConfigured();
        $failing = (int) ($row->failing ?? 0);
        $expiring = (int) ($row->expiring ?? 0);
        $problems = [];

        if ($paused) {
            $problems[] = __('Bankkopplingen är pausad (NONOX_BANKING_PAUSED). Inget hämtas från banken.');
        }

        if ($failing > 0) {
            $problems[] = $failing === 1
                ? __('Hämtningen misslyckas för 1 bankkoppling sedan minst :count försök.', ['count' => BankConnection::FAILURES_TO_TELL])
                : __('Hämtningen misslyckas för :number bankkopplingar sedan minst :count försök.', ['number' => $failing, 'count' => BankConnection::FAILURES_TO_TELL]);
        }

        return [
            'driver' => BankDataProviders::driver(),
            'configured' => $configured,
            'paused' => $paused,
            'pause_env' => 'NONOX_BANKING_PAUSED',
            'active' => (int) ($row->active ?? 0),
            'failing' => $failing,
            'retrying' => (int) ($row->retrying ?? 0),
            'expiring' => $expiring,
            'last_fetch' => is_string($row?->last_fetch) ? CarbonImmutable::parse($row->last_fetch)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null,
            'problems' => $problems,
        ];
    }

    /** @return array{driver: string, configured: bool, paused: bool, pause_env: string, linked: int, participant_errors: int, stale_polls: int, queued: int, stuck: int, waiting_for_pause: int, failed_week: int, events_failed_week: int, problems: list<string>} */
    private function peppol(): array
    {
        $now = CarbonImmutable::now();
        $stuckBefore = $now->subMinutes(self::STUCK_MINUTES);
        $participants = PeppolParticipant::query()
            ->whereNotNull('provider_account')
            ->selectRaw('count(*) as linked')
            ->selectRaw('count(*) filter (where last_error is not null) as errors')
            ->selectRaw('count(*) filter (where last_polled_at is null or last_polled_at < ?) as stale', [$stuckBefore])
            ->toBase()
            ->first();
        $transmissions = PeppolTransmission::query()
            ->selectRaw('count(*) filter (where status = ?) as queued', [TransmissionStatus::Queued->value])
            ->selectRaw('count(*) filter (where status = ? and queued_at < ?) as stuck', [TransmissionStatus::Queued->value, $stuckBefore])
            ->selectRaw('count(*) filter (where status = ? and last_error = ?) as waiting', [TransmissionStatus::Queued->value, TransmitPeppolDocument::PAUSED])
            ->selectRaw('count(*) filter (where status = ? and failed_at >= ?) as failed', [TransmissionStatus::Failed->value, $now->subDays(7)])
            ->toBase()
            ->first();
        $eventsFailed = AuditLog::query()->where('action', 'peppol.event_failed')->where('created_at', '>=', $now->subDays(7))->count();

        $paused = AccessPoints::isPaused();
        $configured = AccessPoints::isConfigured();
        $linked = (int) ($participants->linked ?? 0);
        $stale = (int) ($participants->stale ?? 0);
        $stuck = (int) ($transmissions->stuck ?? 0);
        $failed = (int) ($transmissions->failed ?? 0);
        $problems = [];

        if ($paused) {
            $problems[] = __('Peppol är pausat (NONOX_PEPPOL_PAUSED). Inget skickas eller hämtas.');
        }

        if ($stuck > 0) {
            $problems[] = $stuck === 1
                ? __('1 e-faktura har väntat på att skickas i mer än en timme.')
                : __(':count e-fakturor har väntat på att skickas i mer än en timme.', ['count' => $stuck]);
        }

        if ($configured && ! $paused && $stale > 0) {
            $problems[] = __(':count företag har inte hämtats från Peppol-operatören den senaste timmen. Kör peppol:sync och läs loggen.', ['count' => $stale]);
        }

        if ($eventsFailed > 0) {
            $problems[] = __(':count händelser från Peppol-operatören gick inte att hantera den senaste veckan. peppol:sync hämtar dokumenten igen.', ['count' => $eventsFailed]);
        }

        return [
            'driver' => AccessPoints::driver(),
            'configured' => $configured,
            'paused' => $paused,
            'pause_env' => 'NONOX_PEPPOL_PAUSED',
            'linked' => $linked,
            'participant_errors' => (int) ($participants->errors ?? 0),
            'stale_polls' => $stale,
            'queued' => (int) ($transmissions->queued ?? 0),
            'stuck' => $stuck,
            'waiting_for_pause' => (int) ($transmissions->waiting ?? 0),
            'failed_week' => $failed,
            'events_failed_week' => $eventsFailed,
            'problems' => $problems,
        ];
    }
}
