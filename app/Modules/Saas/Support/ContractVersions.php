<?php

declare(strict_types=1);

namespace App\Modules\Saas\Support;

use App\Domain\Invoicing\AgreementStatus;
use App\Models\Company;
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
    public function recordRecurringInvoice(RecurringInvoice $agreement, ?VersionCause $cause, ?string $effectiveFrom, ?int $userId, ?CarbonImmutable $today = null): ?SaasContractVersion
    {
        $today ??= CarbonImmutable::today();
        $agreement->loadMissing('rows');
        $ended = $agreement->status === AgreementStatus::Ended;
        $cancel = match (true) {
            $ended => $effectiveFrom ?? $agreement->next_invoice_date->max($today)->toDateString(),
            $agreement->end_date !== null => self::firstInvoiceDateAfter($agreement, $agreement->end_date)->toDateString(),
            default => null,
        };

        $previous = self::latest(SourceType::RecurringInvoice, $agreement->id);
        $cause ??= $previous === null ? VersionCause::Created : (($cancel !== null && $previous->cancel_effective_on === null) ? VersionCause::CancelScheduled : VersionCause::Changed);
        $from = match (true) {
            $effectiveFrom !== null => $effectiveFrom,
            $cause === VersionCause::Backfill => ($agreement->created_at ?? $today)->toDateString(),
            $cause === VersionCause::Created => $agreement->next_invoice_date->min($today)->toDateString(),
            $cause === VersionCause::Ended => $cancel ?? $today->toDateString(),
            default => $today->toDateString(),
        };

        $lines = $agreement->rows->map(fn (RecurringInvoiceRow $row): array => (new RevenueLine(
            "r{$row->position}",
            $row->description,
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
            'status' => $agreement->status === AgreementStatus::Paused ? ContractStatus::Paused : ContractStatus::Active,
            'starts_on' => null,
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
        ], $previous);
    }

    public function recordCrmAgreement(Agreement $agreement, ?VersionCause $cause, ?int $userId, ?CarbonImmutable $today = null): ?SaasContractVersion
    {
        $today ??= CarbonImmutable::today();
        $agreement->loadMissing('account:id,customer_id');
        $customerId = $agreement->account?->customer_id;
        $previous = self::latest(SourceType::CrmAgreement, (string) $agreement->id);
        $cancel = self::crmCancel($agreement);
        $cause ??= $previous === null ? VersionCause::Created : (($cancel !== null && $previous->cancel_effective_on === null) ? VersionCause::CancelScheduled : VersionCause::Changed);
        $from = in_array($cause, [VersionCause::Created, VersionCause::Backfill], true)
            ? $agreement->start_date->toDateString()
            : $today->toDateString();

        [$line, $excluded] = self::crmLine($agreement);

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
                'title' => $agreement->title,
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

        foreach ($agreements as $agreement) {
            $has = in_array($agreement->id, $known, true);
            $version = $this->recordRecurringInvoice($agreement, VersionCause::Backfill, $has ? $today->toDateString() : null, $userId, $today);
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

        return [new RevenueLine('value', $agreement->title, 'SEK', $agreement->value_ore, 'month', $months), null];
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
    private function append(array $attributes, ?SaasContractVersion $previous): ?SaasContractVersion
    {
        if ($previous !== null && self::content($attributes) === self::content([
            'customer_id' => $previous->customer_id,
            'status' => $previous->status,
            'starts_on' => $previous->starts_on?->toDateString(),
            'cancel_effective_on' => $previous->cancel_effective_on?->toDateString(),
            'excluded' => $previous->excluded,
            'lines' => $previous->lines,
        ])) {
            return null;
        }

        $attributes['recorded_at'] = now();
        $attributes['sha256'] = hash('sha256', self::canonical([
            ...$attributes,
            'recorded_at' => $attributes['recorded_at']->toIso8601String(),
        ]));

        return SaasContractVersion::create($attributes);
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
