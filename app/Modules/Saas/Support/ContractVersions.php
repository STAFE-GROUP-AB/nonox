<?php

declare(strict_types=1);

namespace App\Modules\Saas\Support;

use App\Domain\Invoicing\AgreementStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRow;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\AgreementKind;
use App\Modules\Crm\Domain\ValuePeriod;
use App\Modules\Crm\Models\Agreement;
use App\Modules\ModuleManager;
use App\Modules\Saas\Domain\ContractStatus;
use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\RevenueLine;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Domain\VersionCause;
use App\Modules\Saas\Models\SaasContractVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes contract versions (ADR-080 §3.1) from NONOX's own agreements: an
 * avtalsfakturering, or a CRM agreement that no avtalsfakturering bills. A
 * version is written only when what counts has changed (customer, status,
 * dates, lines), so saving the notes of an agreement writes nothing.
 *
 * Choices (NONOX, rule sheet SA-R2, SA-R15):
 * - Avtalsfakturering bills in advance (ADR-080 §3.5 default): an agreement
 *   with an end date counts until the first scheduled invoice date after it,
 *   the end of the last paid period. Ending it counts until the next invoice
 *   date that will no longer come, or today if that has passed.
 * - Its amounts are in kronor: the invoices it makes are (RunRecurringInvoice).
 * - A CRM agreement counts its value per month, quarter or year; a total
 *   value only with a start and an end date (total ÷ months). A terminated
 *   agreement ends with its current period, or after the notice period when
 *   it runs until further notice. A ramavtal, a value of 0, an account
 *   without a customer and an agreement billed by an avtalsfakturering are
 *   recorded but not counted, and listed under Källor.
 */
final class ContractVersions
{
    /** The fields of a version that describe the contract. */
    private const array FIELDS = ['customer_id', 'status', 'starts_on', 'cancel_effective_on', 'excluded', 'lines'];

    /**
     * @param  ?string  $effectiveFrom  "Gäller från", or for a backfill the date the agreement started
     */
    public function recordRecurringInvoice(RecurringInvoice $agreement, ?VersionCause $cause, ?string $effectiveFrom, ?int $userId, ?CarbonImmutable $today = null): ?SaasContractVersion
    {
        $today ??= CarbonImmutable::today();
        self::lock($agreement->company_id);
        $agreement->loadMissing('rows');
        $previous = self::latest(SourceType::RecurringInvoice, $agreement->id);
        $ended = $agreement->status === AgreementStatus::Ended;
        // Ending a paused agreement: nothing is paid ahead, so it stops on its "Gäller från" date and stays paused until then.
        $endedWhilePaused = $ended && $previous?->status === ContractStatus::Paused;
        $cancel = match (true) {
            $endedWhilePaused => $effectiveFrom ?? $today->toDateString(),
            $ended => $effectiveFrom ?? $agreement->next_invoice_date->max($today)->toDateString(),
            $agreement->end_date !== null => self::firstInvoiceDateAfter($agreement, $agreement->end_date)->toDateString(),
            default => null,
        };

        $cause ??= $previous === null ? VersionCause::Created : (($cancel !== null && $previous->cancel_effective_on === null) ? VersionCause::CancelScheduled : VersionCause::Changed);
        $from = match (true) {
            // Known from today that it ends on the cancel date ("Gäller från"); a back-dated end applies from that date.
            $cause === VersionCause::Ended => min($today->toDateString(), (string) $cancel),
            $effectiveFrom !== null => $effectiveFrom,
            $cause === VersionCause::Backfill => ($agreement->created_at ?? $today)->toDateString(),
            // A new agreement counts from the start of its first billed period (review decision 12).
            $cause === VersionCause::Created => $agreement->next_invoice_date->toDateString(),
            default => $today->toDateString(),
        };

        // Before its first invoice, moving the next invoice date moves the start (re-check A, case a).
        $startsOn = $previous?->starts_on?->toDateString();
        $oldNext = is_array($previous?->source_ref) ? ($previous->source_ref['next_invoice_date'] ?? null) : null;
        $newNext = $agreement->next_invoice_date->toDateString();

        if ($previous !== null && is_string($oldNext) && $oldNext !== $newNext && $effectiveFrom === null
            && ($previous->starts_on !== null || $previous->effective_from->toDateString() === $oldNext)
            && ! Invoice::query()->where('recurring_invoice_id', $agreement->id)->exists()) {
            $startsOn = $newNext;
            $from = min($from, $oldNext, $newNext);
        }

        // Ids, amounts and enums only: the row's text is read from the agreement when a page shows it (GDPR, review 7).
        $lines = $agreement->rows->map(fn (RecurringInvoiceRow $row): array => (new RevenueLine(
            "r{$row->position}",
            '',
            'SEK',
            $row->net_ore->ore,
            'month',
            $agreement->interval_months,
        ))->toArray())->values()->all();

        return $this->append([
            'source_type' => SourceType::RecurringInvoice,
            'source_id' => $agreement->id,
            'customer_id' => $agreement->customer_id,
            'effective_from' => $from,
            'recorded_by' => $userId,
            'cause' => $cause,
            'status' => $agreement->status === AgreementStatus::Paused || $endedWhilePaused ? ContractStatus::Paused : ContractStatus::Active,
            'starts_on' => $startsOn,
            'cancel_effective_on' => $cancel,
            'excluded' => null,
            'lines' => $lines,
            'source_ref' => [
                'number' => $agreement->number,
                'interval_months' => $agreement->interval_months,
                'next_invoice_date' => $agreement->next_invoice_date->toDateString(),
                'end_date' => $agreement->end_date?->toDateString(),
                'agreement_status' => $agreement->status->value,
            ],
        ], $previous, $effectiveFrom !== null && $cause !== VersionCause::Backfill);
    }

    public function recordCrmAgreement(Agreement $agreement, ?VersionCause $cause, ?int $userId, ?CarbonImmutable $today = null): ?SaasContractVersion
    {
        $today ??= CarbonImmutable::today();
        self::lock($agreement->company_id);
        $agreement->loadMissing('account:id,customer_id');
        $customerId = $agreement->account?->customer_id;
        $previous = self::latest(SourceType::CrmAgreement, (string) $agreement->id);
        $cancel = self::crmCancel($agreement);
        $cause ??= $previous === null ? VersionCause::Created : (($cancel !== null && $previous->cancel_effective_on === null) ? VersionCause::CancelScheduled : VersionCause::Changed);
        $from = in_array($cause, [VersionCause::Created, VersionCause::Backfill], true)
            ? $agreement->start_date->toDateString()
            : $today->toDateString();

        [$line, $excluded] = self::crmLine($agreement);

        if (! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $agreement->company_id)) {
            $excluded = ExclusionReason::CrmOff;
        }

        return $this->append([
            'source_type' => SourceType::CrmAgreement,
            'source_id' => (string) $agreement->id,
            'customer_id' => $customerId,
            'effective_from' => $from,
            'recorded_by' => $userId,
            'cause' => $cause,
            'status' => ContractStatus::Active,
            'starts_on' => $agreement->start_date->toDateString(),
            'cancel_effective_on' => $cancel,
            'excluded' => $excluded ?? ($customerId === null ? ExclusionReason::NoCustomer : null),
            'lines' => $line === null ? [] : [$line->toArray()],
            'source_ref' => [
                'kind' => $agreement->kind->value,
                'value_period' => $agreement->value_period->value,
                'recurring_invoice_id' => $agreement->recurring_invoice_id,
                'end_date' => $agreement->end_date?->toDateString(),
                'terminated_on' => $agreement->terminated_on?->toDateString(),
            ],
        ], $previous);
    }

    /**
     * Turning the module on (ADR-080 §3.1): one BACKFILL version per active or
     * paused agreement that has none, from its current state and its start; an
     * agreement whose state changed while the module was off gets one from today.
     * Idempotent: an unchanged agreement writes nothing.
     */
    public function backfill(Company $company, ?int $userId, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $written = 0;

        $agreements = RecurringInvoice::query()
            ->with('rows')
            ->whereIn('status', [AgreementStatus::Active->value, AgreementStatus::Paused->value])
            ->orderBy('number')
            ->get();
        $known = SaasContractVersion::query()->where('source_type', SourceType::RecurringInvoice->value)->distinct()->pluck('source_id')->all();
        // An agreement may be entered after its first invoices (catching up): it started with the earliest of them.
        $firstInvoice = Invoice::query()
            ->whereIn('recurring_invoice_id', $agreements->pluck('id')->all())
            ->where('kind', Invoice::INVOICE)
            ->groupBy('recurring_invoice_id')
            ->selectRaw('recurring_invoice_id, min(invoice_date) as first_date')
            ->pluck('first_date', 'recurring_invoice_id');

        foreach ($agreements as $agreement) {
            $has = in_array($agreement->id, $known, true);
            $started = min(($agreement->created_at ?? $today)->toDateString(), substr((string) ($firstInvoice[$agreement->id] ?? '9999-12-31'), 0, 10));
            $version = $this->recordRecurringInvoice($agreement, VersionCause::Backfill, $has ? $today->toDateString() : $started, $userId, $today);
            $written += $version === null ? 0 : 1;
        }

        if (! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company)) {
            return $written;
        }

        $knownCrm = SaasContractVersion::query()->where('source_type', SourceType::CrmAgreement->value)->distinct()->pluck('source_id')->all();
        $crm = Agreement::query()->with('account:id,customer_id')->orderBy('id')->get()
            ->filter(fn (Agreement $agreement): bool => in_array($agreement->status($today), [Agreement::ACTIVE, Agreement::UPCOMING, Agreement::TERMINATED], true));

        foreach ($crm as $agreement) {
            $has = in_array((string) $agreement->id, $knownCrm, true);
            $version = $this->recordCrmAgreement($agreement, $has ? VersionCause::Changed : VersionCause::Backfill, $userId, $today);
            $written += $version === null ? 0 : 1;
        }

        return $written;
    }

    /**
     * Keeps the agreement register's versions in step with the CRM module
     * (review 4): while CRM is off its agreements are not maintained, so each
     * gets a version from today that leaves it out (CRM_OFF, listed under
     * Källor); when CRM is on again each is recorded as it stands. Versions,
     * not a filter, so a closed month still reproduces.
     */
    public function syncCrm(Company $company, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $crmOn = app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company);
        $latest = SaasContractVersion::query()
            ->where('source_type', SourceType::CrmAgreement->value)
            ->whereIn('id', SaasContractVersion::query()->selectRaw('max(id)')->where('source_type', SourceType::CrmAgreement->value)->groupBy('source_id'))
            ->get();
        $written = 0;

        foreach ($latest as $version) {
            $off = $version->excluded === ExclusionReason::CrmOff;

            if ($crmOn && $off) {
                $agreement = Agreement::query()->find((int) $version->source_id);
                $written += $agreement instanceof Agreement && $this->recordCrmAgreement($agreement, VersionCause::Changed, null, $today) !== null ? 1 : 0;
            }

            if (! $crmOn && ! $off) {
                self::lock($company->id);
                $this->write([
                    ...self::state($version),
                    'source_type' => SourceType::CrmAgreement,
                    'source_id' => $version->source_id,
                    'effective_from' => $today->toDateString(),
                    'recorded_by' => null,
                    'cause' => VersionCause::Changed,
                    'excluded' => ExclusionReason::CrmOff,
                    'source_ref' => $version->source_ref,
                ]);
                $written++;
            }
        }

        return $written;
    }

    /** The first scheduled invoice date after the date: the agreement's paid period then ends. */
    public static function firstInvoiceDateAfter(RecurringInvoice $agreement, CarbonImmutable $date): CarbonImmutable
    {
        $next = $agreement->next_invoice_date;

        // Step back to the first scheduled date not before $date's period, then forward.
        while ($next->greaterThan($date)) {
            $earlier = $next->startOfMonth()->subMonthsNoOverflow($agreement->interval_months);
            $earlier = $earlier->setDay(min($agreement->invoice_day, $earlier->daysInMonth));

            if ($earlier->lessThanOrEqualTo($date)) {
                return $next;
            }

            $next = $earlier;
        }

        while (! $next->greaterThan($date)) {
            $next = $agreement->dateAfter($next);
        }

        return $next;
    }

    private static function crmCancel(Agreement $agreement): ?string
    {
        if ($agreement->renews()) {
            return null;
        }

        if ($agreement->end_date !== null) {
            return $agreement->end_date->addDay()->toDateString();
        }

        if ($agreement->terminated_on !== null) {
            return $agreement->terminated_on->addMonthsNoOverflow($agreement->notice_months)->toDateString();
        }

        return null;
    }

    /** @return array{0: ?RevenueLine, 1: ?ExclusionReason} */
    private static function crmLine(Agreement $agreement): array
    {
        if ($agreement->recurring_invoice_id !== null) {
            return [null, ExclusionReason::LinkedRecurringInvoice];
        }

        if ($agreement->kind === AgreementKind::Framework) {
            return [null, ExclusionReason::Framework];
        }

        if ($agreement->value_ore <= 0) {
            return [null, ExclusionReason::NoValue];
        }

        $months = match ($agreement->value_period) {
            ValuePeriod::Month => 1,
            ValuePeriod::Quarter => 3,
            ValuePeriod::Year => 12,
            ValuePeriod::Total => $agreement->end_date === null ? null : max(1, (int) round($agreement->start_date->diffInMonths($agreement->end_date->addDay(), true))),
        };

        if ($months === null) {
            return [null, ExclusionReason::TotalWithoutDates];
        }

        return [new RevenueLine('value', '', 'SEK', $agreement->value_ore, 'month', $months), null];
    }

    private static function latest(SourceType $type, string $id): ?SaasContractVersion
    {
        return SaasContractVersion::query()
            ->where('source_type', $type->value)
            ->where('source_id', $id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    /**
     * Writes the version when it changes what is known about the contract's
     * timeline (re-check A): never an exact duplicate of the latest version,
     * never a silent loss of a change of date.
     *
     * - Same content as the latest version: written only when the person gave
     *   an explicit "Gäller från" and the contract on that date (or a pending
     *   later change) differs, so bringing a change forward or backdating it
     *   is kept; without an explicit date nothing changed.
     * - A moved start (before the first invoice): written in full from the
     *   earlier of the two dates.
     * - Otherwise a pending later change is kept (see below).
     *
     * @param  array<string, mixed>  $attributes
     * @param  bool  $explicit  the person gave the effective date ("Gäller från")
     */
    private function append(array $attributes, ?SaasContractVersion $previous, bool $explicit = false): ?SaasContractVersion
    {
        $from = (string) $attributes['effective_from'];

        if ($previous === null) {
            return $this->write($attributes);
        }

        $sameAsLatest = self::content($attributes) === self::content(self::state($previous));

        if ($sameAsLatest && $from === $previous->effective_from->toDateString()) {
            return null;
        }

        $versions = SaasContractVersion::query()
            ->where('source_type', $attributes['source_type'] instanceof SourceType ? $attributes['source_type']->value : $attributes['source_type'])
            ->where('source_id', $attributes['source_id'])
            ->orderBy('id')
            ->get();
        $pending = self::pending($versions, $from);
        $inForce = $versions->filter(fn (SaasContractVersion $version): bool => $version->effective_from->toDateString() <= $from)->last();

        if ($sameAsLatest && $explicit && $from > $previous->effective_from->toDateString()) {
            /*
             * The latest change moved to a later date (re-check 2 C): from its old date the
             * contract is again as it was before that change (or not yet started), and the
             * change applies from the new date.
             */
            $old = $previous->effective_from->toDateString();
            $before = $versions
                ->filter(fn (SaasContractVersion $version): bool => $version->id < $previous->id && $version->effective_from->toDateString() < $old)
                ->last();

            $this->write($before === null
                ? [...$attributes, 'effective_from' => $old, 'starts_on' => $from]
                : [...$attributes, ...self::state($before), 'effective_from' => $old]);

            return $this->write($attributes);
        }

        if ($sameAsLatest) {
            $unchanged = $inForce !== null && self::content(self::state($inForce)) === self::content($attributes) && $pending === [];

            return ! $explicit || $unchanged ? null : $this->write($attributes);
        }

        $movedStart = self::field($attributes, 'starts_on') !== self::field(self::state($previous), 'starts_on');

        if ($pending === [] || $movedStart) {
            return $this->write($attributes);
        }

        /*
         * A change dated before a change that is still to come (review 3): the
         * agreement holds the later state, so the fields the person did not
         * touch are taken from the version in force on this date, and every
         * pending change is written again after this one with the person's
         * change applied, so it still takes effect on its own date.
         */
        $latest = self::state($previous);
        $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => self::field($attributes, $field) !== self::field($latest, $field)));
        $base = $inForce;
        $written = null;

        if ($base !== null) {
            $now = $attributes;

            foreach (array_diff(self::FIELDS, $changed) as $field) {
                $now[$field] = self::state($base)[$field];
            }

            $written = $this->write($now);
        }

        foreach ($pending as $version) {
            $again = [...$attributes, ...self::state($version), 'effective_from' => $version->effective_from->toDateString(), 'cause' => $version->cause];

            foreach ($changed as $field) {
                $again[$field] = $attributes[$field];
            }

            $written = $this->write($again);
        }

        return $written;
    }

    /**
     * The versions dated after $from that still take effect on their own date:
     * no version recorded after them is dated on or before it.
     *
     * @param  Collection<int, SaasContractVersion>  $versions  sorted by id
     * @return list<SaasContractVersion> by effective date
     */
    private static function pending(Collection $versions, string $from): array
    {
        $pending = [];
        $list = $versions->values()->all();

        foreach ($list as $index => $version) {
            $date = $version->effective_from->toDateString();

            if ($date <= $from) {
                continue;
            }

            $overridden = false;

            foreach (array_slice($list, $index + 1) as $later) {
                $overridden = $overridden || $later->effective_from->toDateString() <= $date;
            }

            if (! $overridden) {
                $pending[] = $version;
            }
        }

        usort($pending, fn (SaasContractVersion $a, SaasContractVersion $b): int => [$a->effective_from->toDateString(), $a->id] <=> [$b->effective_from->toDateString(), $b->id]);

        return $pending;
    }

    /** @return array{customer_id: ?int, status: ContractStatus, starts_on: ?string, cancel_effective_on: ?string, excluded: ?ExclusionReason, lines: list<array<string, mixed>>} */
    private static function state(SaasContractVersion $version): array
    {
        return [
            'customer_id' => $version->customer_id,
            'status' => $version->status,
            'starts_on' => $version->starts_on?->toDateString(),
            'cancel_effective_on' => $version->cancel_effective_on?->toDateString(),
            'excluded' => $version->excluded,
            'lines' => $version->lines,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private static function field(array $attributes, string $field): string
    {
        $value = $attributes[$field] ?? null;

        if ($field === 'lines') {
            $value = array_map(fn (array $line): array => RevenueLine::fromArray($line)->toArray(), (array) $value);
        }

        return self::canonical(['v' => $value]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function write(array $attributes): SaasContractVersion
    {
        $attributes['recorded_at'] = now();
        $attributes['sha256'] = hash('sha256', self::canonical([
            ...$attributes,
            'recorded_at' => $attributes['recorded_at']->toIso8601String(),
        ]));

        return SaasContractVersion::create($attributes);
    }

    /**
     * One writer per company at a time, and never while a month is being
     * closed: the same transaction lock as the recompute (review 11). It is
     * held until the saving transaction commits, so a close never reads past
     * a version that is not committed yet, and a version written after a
     * close gets a higher id than the close used.
     */
    public static function lock(int $companyId): void
    {
        DB::select('select pg_advisory_xact_lock(80080, ?)', [$companyId]);
    }

    /** @param  array<string, mixed>  $attributes */
    private static function content(array $attributes): string
    {
        return self::canonical([
            'customer_id' => $attributes['customer_id'] === null ? null : (int) $attributes['customer_id'],
            'status' => $attributes['status'] instanceof ContractStatus ? $attributes['status']->value : $attributes['status'],
            'starts_on' => $attributes['starts_on'],
            'cancel_effective_on' => $attributes['cancel_effective_on'],
            'excluded' => $attributes['excluded'] instanceof ExclusionReason ? $attributes['excluded']->value : $attributes['excluded'],
            'lines' => array_map(fn (array $line): array => RevenueLine::fromArray($line)->toArray(), (array) $attributes['lines']),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private static function canonical(array $data): string
    {
        $normalise = function (mixed $value) use (&$normalise): mixed {
            if ($value instanceof \BackedEnum) {
                return $value->value;
            }

            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalise, $value);
        };

        return (string) json_encode($normalise($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
