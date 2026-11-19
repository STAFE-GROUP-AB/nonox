<?php

declare(strict_types=1);

namespace App\Domain\Signing\Retention;

use App\Domain\Signing\EnvelopeStatus;
use App\Models\SigningEnvelope;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retention of signing envelopes (ADR-046 amendment 2026-11-18, gap 11,
 * package S16). When an envelope finishes or expires it gets a class and the
 * last day it is kept (retention_until, a Stockholm date):
 *
 * - ACCOUNTING, a completed quote, agreement or ÄTA (räkenskapsinformation,
 *   BFL 5 kap. 7 § and 7 kap. 2 §): the later of 31 December seven years after
 *   the end of the fiscal year it was completed in, and the company's period
 *   for signed documents (it is also the evidence of a contract);
 * - OTHER, a completed standalone document: the company's period, 7–25 years
 *   after completion (default 10, owner decision 12); a member with
 *   signing:settings can move it to ACCOUNTING;
 * - UNSIGNED, declined, withdrawn or expired (also when a signer had already
 *   signed: no agreement came about): twelve months after it closed.
 *
 * The class only moves up and the date only moves later (PostgreSQL trigger,
 * which also checks the BFL floor against fiscal_years). A legal hold stops
 * every deletion.
 *
 * TODO(legal-verify): the 10-year default (preskriptionslagen 2 §) against
 * art. 5.1 e; whether every signed CRM agreement is räkenskapsinformation;
 * whether twelve months is right for an envelope where some but not all
 * signers signed.
 */
final class SigningRetention
{
    public const int DEFAULT_YEARS = 10;

    public const int MIN_YEARS = 7;

    public const int MAX_YEARS = 25;

    public const int UNSIGNED_MONTHS = 12;

    /** An uploaded standalone document that was never sent is deleted this many days after the upload. */
    public const int UNSENT_DAYS = 90;

    public const string ZONE = 'Europe/Stockholm';

    /** The document types that are not räkenskapsinformation by default (the module's standalone documents). */
    public const array OTHER_TYPES = ['DOCUMENT'];

    public static function classFor(string $documentType, bool $completed): SigningRetentionClass
    {
        if (! $completed) {
            return SigningRetentionClass::Unsigned;
        }

        return in_array($documentType, self::OTHER_TYPES, true) ? SigningRetentionClass::Other : SigningRetentionClass::Accounting;
    }

    /** The company's period for signed documents, in years. */
    public static function years(int $companyId): int
    {
        $years = DB::table('teams')->where('id', $companyId)->value('signing_retention_years');

        return is_numeric($years) ? max(self::MIN_YEARS, min(self::MAX_YEARS, (int) $years)) : self::DEFAULT_YEARS;
    }

    /**
     * The retention columns for an envelope that finishes or expires now, to
     * be written in the same update. Never lower than what it already has.
     *
     * @return array{retention_class: string, retention_until: string}
     */
    public static function stamp(SigningEnvelope $envelope, bool $completed, ?CarbonImmutable $at = null): array
    {
        $class = self::classFor($envelope->document_type, $completed);
        $current = $envelope->retention_class === null ? null : SigningRetentionClass::tryFrom($envelope->retention_class);

        if ($current instanceof SigningRetentionClass && $current->rank() > $class->rank()) {
            $class = $current;
        }

        $until = self::until($class, $envelope->company_id, $at ?? CarbonImmutable::now());

        if ($envelope->retention_until !== null && $envelope->retention_until->greaterThan($until)) {
            $until = $envelope->retention_until;
        }

        return ['retention_class' => $class->value, 'retention_until' => $until->toDateString()];
    }

    /** The last day an envelope of the class, closed at the time, is kept. */
    public static function until(SigningRetentionClass $class, int $companyId, CarbonImmutable $at): CarbonImmutable
    {
        $day = $at->setTimezone(self::ZONE)->startOfDay();

        return match ($class) {
            SigningRetentionClass::Unsigned => $day->addMonthsNoOverflow(self::UNSIGNED_MONTHS),
            SigningRetentionClass::Other => $day->addYearsNoOverflow(self::years($companyId)),
            SigningRetentionClass::Accounting => self::later($day->addYearsNoOverflow(self::years($companyId)), self::accountingFloor($companyId, $day)),
        };
    }

    /**
     * 31 December seven years after the end of the fiscal year that holds the
     * date (BFL 7 kap. 2 §). When that fiscal year is not yet in the books it
     * is derived from the company's first month, one year later to be safe:
     * a first or changed fiscal year may run up to 18 months (BFL 3 kap. 3 §).
     */
    public static function accountingFloor(int $companyId, CarbonImmutable $date): CarbonImmutable
    {
        $day = $date->toDateString();
        $end = DB::table('fiscal_years')->where('company_id', $companyId)->where('start_date', '<=', $day)->where('end_date', '>=', $day)->orderByDesc('end_date')->value('end_date');

        if (is_string($end)) {
            return CarbonImmutable::createFromDate(CarbonImmutable::parse($end)->year + 7, 12, 31, self::ZONE)->startOfDay();
        }

        $startMonth = (int) (DB::table('teams')->where('id', $companyId)->value('fiscal_year_start_month') ?? 1);
        $endYear = $startMonth <= 1 || $date->month < $startMonth ? $date->year : $date->year + 1;

        return CarbonImmutable::createFromDate($endYear + 1 + 7, 12, 31, self::ZONE)->startOfDay();
    }

    /**
     * The last day a finished envelope must be kept today, from its class and
     * from what it is linked to, never earlier than its stamped date; null
     * while the agreement it signed still runs (gap 11, review 2026-11-19):
     *
     * - ACCOUNTING: also 31 December seven years after the fiscal year of the
     *   latest booking made from the document (latestBookings): BFL 5 kap. 7 §
     *   and 7 kap. 2 § tie the period to the fiscal year of the business
     *   event, not of the signature;
     * - an agreement: the company's period counted from its end (or its
     *   termination), as the limitation period runs from then.
     */
    public static function requiredUntil(SigningEnvelope $envelope, ?CarbonImmutable $today = null): ?CarbonImmutable
    {
        return self::requiredForMany([$envelope], $today)[$envelope->id] ?? null;
    }

    /**
     * requiredUntil for many envelopes of one company in a fixed number of
     * queries, whatever their number (the prune's batch).
     *
     * @param  iterable<SigningEnvelope>  $envelopes
     * @return array<string, ?CarbonImmutable> envelope id => the required date, null while its agreement runs
     */
    public static function requiredForMany(iterable $envelopes, ?CarbonImmutable $today = null): array
    {
        $envelopes = collect($envelopes)->values();

        if ($envelopes->isEmpty()) {
            return [];
        }

        $company = (int) $envelopes->first()->company_id;
        $team = DB::table('teams')->where('id', $company)->first(['signing_retention_years', 'fiscal_year_start_month']);
        $years = is_numeric($team?->signing_retention_years) ? max(self::MIN_YEARS, min(self::MAX_YEARS, (int) $team->signing_retention_years)) : self::DEFAULT_YEARS;
        $startMonth = (int) ($team->fiscal_year_start_month ?? 1);
        $fiscalYears = DB::table('fiscal_years')->where('company_id', $company)->orderByDesc('end_date')->get(['start_date', 'end_date'])->all();
        $agreements = DB::table('crm_agreements')->where('company_id', $company)->whereIn('id', $envelopes->pluck('agreement_id')->filter()->unique()->values()->all())
            ->get(['id', 'end_date', 'terminated_on', 'auto_renew'])->keyBy('id');
        $bookings = self::latestBookings($envelopes->filter(fn (SigningEnvelope $envelope): bool => $envelope->retention_class === SigningRetentionClass::Accounting->value));
        $floor = fn (CarbonImmutable $date): CarbonImmutable => self::floorFrom($fiscalYears, $startMonth, $date);
        $required = [];

        foreach ($envelopes as $envelope) {
            $class = SigningRetentionClass::tryFrom((string) $envelope->retention_class);
            $until = $envelope->retention_until;

            if (! $class instanceof SigningRetentionClass || $class === SigningRetentionClass::Unsigned) {
                $required[$envelope->id] = $until;

                continue;
            }

            $agreement = $agreements->get($envelope->agreement_id);
            $state = $agreement === null ? ['running' => false, 'ended_on' => null] : self::agreementRunning($agreement->end_date, $agreement->terminated_on, (bool) $agreement->auto_renew, $today);

            if ($state['running']) {
                $required[$envelope->id] = null;

                continue;
            }

            $completed = ($envelope->completed_at ?? CarbonImmutable::now())->setTimezone(self::ZONE)->startOfDay();
            $candidates = [$completed->addYearsNoOverflow($years)];

            if ($class === SigningRetentionClass::Accounting) {
                $candidates[] = $floor($completed);
            }

            if ($until !== null) {
                $candidates[] = $until;
            }

            if ($state['ended_on'] !== null) {
                $candidates[] = $state['ended_on']->addYearsNoOverflow($years);
            }

            if (($bookings[$envelope->id] ?? null) instanceof CarbonImmutable) {
                $candidates[] = $floor($bookings[$envelope->id]);
            }

            $required[$envelope->id] = array_reduce($candidates, fn (?CarbonImmutable $carry, CarbonImmutable $date): CarbonImmutable => $carry === null || $date->greaterThan($carry) ? $date : $carry);
        }

        return $required;
    }

    /**
     * Moves a finished envelope's date later when what it is linked to says
     * so (a booking in a later fiscal year, an agreement's end). Writes only
     * retention_until, never earlier (trigger). Returns the required date, or
     * null while its agreement runs. A precomputed date (the prune's batch)
     * saves the lookups.
     */
    public static function refresh(SigningEnvelope $envelope, ?CarbonImmutable $today = null, ?CarbonImmutable $required = null, bool $computed = false): ?CarbonImmutable
    {
        $required = $computed ? $required : self::requiredUntil($envelope, $today);

        if ($required === null || $envelope->retention_until === null || ! $required->greaterThan($envelope->retention_until)) {
            return $required;
        }

        $from = $envelope->retention_until->toDateString();
        DB::table('signing_envelopes')->where('id', $envelope->id)->update(['retention_until' => $required->toDateString()]);
        $envelope->setAttribute('retention_until', $required->toDateString());
        $envelope->syncOriginalAttribute('retention_until');
        Audit::record('signing.retention_extended', $envelope, ['envelope_id' => $envelope->id, 'from' => $from, 'to' => $required->toDateString()], $envelope->company_id);

        return $required;
    }

    /**
     * The agreement an envelope signed (CRM): running while it is not
     * terminated and has no end date, an end date ahead, or renews itself;
     * otherwise the day it ended.
     *
     * @return array{running: bool, ended_on: ?CarbonImmutable}
     */
    public static function agreementState(SigningEnvelope $envelope, ?CarbonImmutable $today = null): array
    {
        if ($envelope->agreement_id === null) {
            return ['running' => false, 'ended_on' => null];
        }

        $row = DB::table('crm_agreements')->where('company_id', $envelope->company_id)->where('id', $envelope->agreement_id)->first(['end_date', 'terminated_on', 'auto_renew']);

        if ($row === null) {
            return ['running' => false, 'ended_on' => null];
        }

        return self::agreementRunning($row->end_date, $row->terminated_on, (bool) $row->auto_renew, $today);
    }

    /** @return array{running: bool, ended_on: ?CarbonImmutable} */
    public static function agreementRunning(mixed $endDate, mixed $terminatedOn, bool $autoRenew, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->setTimezone(self::ZONE)->startOfDay();
        $end = is_string($terminatedOn) ? CarbonImmutable::parse($terminatedOn, self::ZONE) : (is_string($endDate) && ! $autoRenew ? CarbonImmutable::parse($endDate, self::ZONE) : null);

        if ($end === null || $end->greaterThanOrEqualTo($today)) {
            return ['running' => true, 'ended_on' => null];
        }

        return ['running' => false, 'ended_on' => $end];
    }

    /**
     * The invoices made from each envelope's document, in a fixed number of
     * queries (review 2026-11-19):
     *
     * - a quote: its own invoice and its orders' (`from_quote_id`, the signer
     *   rows' `order_id` and `invoice_id`), and the invoices of every work
     *   order made from the quote or one of those orders
     *   (`work_orders.sales_document_id` → `work_order_invoices`);
     * - an agreement: the invoices of its recurring invoice;
     * - an ÄTA: the work order invoices that include it.
     *
     * @param  Collection<int, SigningEnvelope>  $envelopes  of one company
     * @return array<string, list<string>> envelope id => invoice ids
     */
    public static function linkedInvoices(Collection $envelopes): array
    {
        if ($envelopes->isEmpty()) {
            return [];
        }

        $company = (int) $envelopes->first()->company_id;
        $ids = $envelopes->pluck('id')->all();
        $quoteIds = $envelopes->pluck('sales_document_id')->filter()->unique()->values()->all();
        $signers = DB::table('crm_signing_requests')->whereIn('envelope_id', $ids)->get(['envelope_id', 'order_id', 'invoice_id']);
        $orders = $quoteIds === [] ? collect() : DB::table('sales_documents')->where('company_id', $company)->whereIn('from_quote_id', $quoteIds)->get(['id', 'from_quote_id']);
        // Every sales document (quote or order) per envelope.
        $documents = [];

        foreach ($envelopes as $envelope) {
            if ($envelope->sales_document_id !== null) {
                $documents[$envelope->id] = [$envelope->sales_document_id, ...$orders->where('from_quote_id', $envelope->sales_document_id)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all()];
            }
        }

        foreach ($signers as $signer) {
            if ($signer->order_id !== null && isset($documents[$signer->envelope_id])) {
                $documents[$signer->envelope_id][] = (string) $signer->order_id;
            }
        }

        $allDocuments = array_values(array_unique(array_merge([], ...array_values($documents))));
        $documentInvoices = $allDocuments === [] ? collect() : DB::table('sales_documents')->where('company_id', $company)->whereIn('id', $allDocuments)->whereNotNull('invoice_id')->pluck('invoice_id', 'id');
        $workOrders = $allDocuments === [] ? collect() : DB::table('work_orders')->where('company_id', $company)->whereIn('sales_document_id', $allDocuments)->get(['id', 'sales_document_id']);
        $changeOrderIds = $envelopes->where('document_type', 'ATA')->pluck('signable_id')->filter(fn (mixed $id): bool => is_string($id) && ctype_digit($id))->values()->all();
        $workOrderInvoices = $workOrders->isEmpty() && $changeOrderIds === [] ? collect() : DB::table('work_order_invoices')->where('company_id', $company)
            ->where(fn ($query) => $query
                ->whereIn('work_order_id', $workOrders->pluck('id')->all())
                ->when($changeOrderIds !== [], fn ($q) => $q->orWhereRaw('exists (select 1 from jsonb_array_elements_text(change_order_ids) e where e = any(?::text[]))', ['{'.implode(',', $changeOrderIds).'}'])))
            ->get(['work_order_id', 'invoice_id', 'change_order_ids']);
        $agreements = DB::table('crm_agreements')->where('company_id', $company)->whereIn('id', $envelopes->pluck('agreement_id')->filter()->unique()->values()->all())->whereNotNull('recurring_invoice_id')->pluck('recurring_invoice_id', 'id');
        $recurring = $agreements->isEmpty() ? collect() : DB::table('invoices')->where('company_id', $company)->whereIn('recurring_invoice_id', $agreements->values()->all())->get(['id', 'recurring_invoice_id']);
        $linked = [];

        foreach ($envelopes as $envelope) {
            $invoices = [];

            foreach ($documents[$envelope->id] ?? [] as $document) {
                if ($documentInvoices->has($document)) {
                    $invoices[] = (string) $documentInvoices->get($document);
                }

                foreach ($workOrders->where('sales_document_id', $document) as $workOrder) {
                    $invoices = [...$invoices, ...$workOrderInvoices->where('work_order_id', $workOrder->id)->pluck('invoice_id')->map(fn (mixed $id): string => (string) $id)->all()];
                }
            }

            foreach ($signers->where('envelope_id', $envelope->id)->whereNotNull('invoice_id') as $signer) {
                $invoices[] = (string) $signer->invoice_id;
            }

            if ($envelope->agreement_id !== null && $agreements->has($envelope->agreement_id)) {
                $invoices = [...$invoices, ...$recurring->where('recurring_invoice_id', $agreements->get($envelope->agreement_id))->pluck('id')->map(fn (mixed $id): string => (string) $id)->all()];
            }

            if ($envelope->document_type === 'ATA' && is_string($envelope->signable_id)) {
                foreach ($workOrderInvoices as $row) {
                    if (in_array($envelope->signable_id, array_map('strval', (array) json_decode((string) $row->change_order_ids, true)), true)) {
                        $invoices[] = (string) $row->invoice_id;
                    }
                }
            }

            $linked[$envelope->id] = array_values(array_unique($invoices));
        }

        return $linked;
    }

    /**
     * The latest day something was booked for each envelope's invoices: the
     * invoice date, the invoice's voucher, a payment (to be safe under
     * kontantmetoden), a credit note of the invoice (its date and voucher) and
     * a write-off (its voucher and date). Null when nothing is booked yet. A
     * fixed number of queries.
     *
     * TODO(legal-verify): whether a payment under kontantmetoden, a credit
     * note or a customer loss in a later fiscal year moves the floor (counted
     * here, to be safe).
     *
     * @param  Collection<int, SigningEnvelope>  $envelopes
     * @return array<string, ?CarbonImmutable>
     */
    public static function latestBookings(Collection $envelopes): array
    {
        $linked = self::linkedInvoices($envelopes->values());
        $all = array_values(array_unique(array_merge([], ...array_values($linked))));

        if ($all === []) {
            return array_fill_keys($envelopes->pluck('id')->all(), null);
        }

        $company = (int) $envelopes->first()?->company_id;
        $invoices = DB::table('invoices')->where('company_id', $company)
            ->where(fn ($query) => $query->whereIn('id', $all)->orWhereIn('credits_invoice_id', $all))
            ->get(['id', 'credits_invoice_id', 'invoice_date', 'issued_at', 'voucher_id', 'write_off_voucher_id', 'written_off_on']);
        $voucherIds = $invoices->pluck('voucher_id')->merge($invoices->pluck('write_off_voucher_id'))->filter()->unique()->values()->all();
        $voucherDates = $voucherIds === [] ? collect() : DB::table('vouchers')->whereIn('id', $voucherIds)->pluck('voucher_date', 'id');
        $payments = DB::table('invoice_payments')->where('company_id', $company)->whereIn('invoice_id', $all)->groupBy('invoice_id')->selectRaw('invoice_id, max(paid_on) as paid_on')->pluck('paid_on', 'invoice_id');
        // The dates that count for each invoice, its credit notes included.
        $dates = [];

        foreach ($invoices as $invoice) {
            $owner = (string) ($invoice->credits_invoice_id !== null && in_array((string) $invoice->credits_invoice_id, $all, true) ? $invoice->credits_invoice_id : $invoice->id);
            $dates[$owner] = [
                ...$dates[$owner] ?? [],
                $invoice->issued_at !== null ? $invoice->invoice_date : null,
                $invoice->voucher_id !== null ? $voucherDates->get($invoice->voucher_id) : null,
                $invoice->write_off_voucher_id !== null ? $voucherDates->get($invoice->write_off_voucher_id) : null,
                $invoice->write_off_voucher_id !== null ? $invoice->written_off_on : null,
            ];
        }

        foreach ($payments as $invoiceId => $paidOn) {
            $dates[(string) $invoiceId][] = $paidOn;
        }

        $latest = [];

        foreach ($envelopes as $envelope) {
            $best = null;

            foreach ($linked[$envelope->id] ?? [] as $invoiceId) {
                foreach ($dates[$invoiceId] ?? [] as $date) {
                    if (is_string($date)) {
                        $day = CarbonImmutable::parse($date, self::ZONE)->startOfDay();
                        $best = $best === null || $day->greaterThan($best) ? $day : $best;
                    }
                }
            }

            $latest[$envelope->id] = $best;
        }

        return $latest;
    }

    /**
     * The BFL floor from preloaded fiscal years (end date descending), as accountingFloor.
     *
     * @param  array<int, \stdClass>  $fiscalYears
     */
    private static function floorFrom(array $fiscalYears, int $startMonth, CarbonImmutable $date): CarbonImmutable
    {
        $day = $date->toDateString();

        foreach ($fiscalYears as $year) {
            if ((string) $year->start_date <= $day && (string) $year->end_date >= $day) {
                return CarbonImmutable::createFromDate(CarbonImmutable::parse((string) $year->end_date)->year + 7, 12, 31, self::ZONE)->startOfDay();
            }
        }

        $endYear = $startMonth <= 1 || $date->month < $startMonth ? $date->year : $date->year + 1;

        return CarbonImmutable::createFromDate($endYear + 1 + 7, 12, 31, self::ZONE)->startOfDay();
    }

    /**
     * Why a finished envelope may not be deleted today, or null when it may:
     * HOLD (a legal hold), RUNNING (the agreement it signed still runs),
     * ACCOUNTING (räkenskapsinformation within its retention, art. 17.3 b),
     * EVIDENCE (a signature within its retention, or an envelope still out
     * that someone has signed: the evidence of a contract, art. 17.3 e). An
     * envelope nobody has signed may always go.
     */
    public static function blocker(SigningEnvelope $envelope, bool $anySigned, ?CarbonImmutable $today = null, bool $running = false): ?string
    {
        $today = ($today ?? CarbonImmutable::now())->setTimezone(self::ZONE)->startOfDay();

        if ($envelope->retention_hold) {
            return 'HOLD';
        }

        if ($running && $envelope->status === EnvelopeStatus::Completed) {
            return 'RUNNING';
        }

        if ($envelope->retention_until !== null && $envelope->retention_until->lessThan($today)) {
            return null;
        }

        if ($envelope->status !== EnvelopeStatus::Completed && ! $anySigned) {
            return null;
        }

        return $envelope->retention_class === SigningRetentionClass::Accounting->value ? 'ACCOUNTING' : 'EVIDENCE';
    }

    /**
     * Gives every envelope that is finished or has expired, and has no class
     * yet, its class and date (the migration, and the prune for an envelope
     * an older worker finished during a deploy). Only the two columns are
     * written; updated_at stays. Across companies on purpose: it runs from
     * the migration without a company.
     */
    public static function backfill(?int $companyId = null): int
    {
        $rows = DB::table('signing_envelopes')
            ->whereNull('retention_class')
            ->where(fn ($query) => $query->whereIn('status', ['COMPLETED', 'DECLINED', 'VOIDED'])->orWhereNotNull('expired_at'))
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->get(['id', 'company_id', 'document_type', 'status', 'completed_at', 'declined_at', 'voided_at', 'expired_at', 'updated_at']);

        foreach ($rows as $row) {
            $completed = $row->status === 'COMPLETED';
            $closed = match ($row->status) {
                'COMPLETED' => $row->completed_at,
                'DECLINED' => $row->declined_at,
                'VOIDED' => $row->voided_at,
                default => $row->expired_at,
            } ?? $row->expired_at ?? $row->updated_at;
            $class = self::classFor((string) $row->document_type, $completed);
            $until = self::until($class, (int) $row->company_id, CarbonImmutable::parse((string) $closed));

            DB::table('signing_envelopes')->where('id', $row->id)->update(['retention_class' => $class->value, 'retention_until' => $until->toDateString()]);
        }

        return $rows->count();
    }

    private static function later(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $b->greaterThan($a) ? $b : $a;
    }
}
