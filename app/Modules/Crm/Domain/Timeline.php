<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceReminder;
use App\Models\SalesDocument;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * An account's timeline (ADR-043): its activities, and from the ledger its
 * quotes and orders, issued invoices and credit invoices, payments and
 * reminders, newest first. One UNION ALL of the six sources with the
 * columns the screen shows, paginated: two queries per page (the count and
 * the page), whatever the number of entries. Every source is scoped to the
 * current company by its model.
 */
final class Timeline
{
    public const int PER_PAGE = 20;

    /**
     * @param  bool  $withLedger  false shows the activities only (a private person's account for someone who may not see invoices)
     * @return LengthAwarePaginator<int, object>
     */
    public function for(Account $account, int $perPage = self::PER_PAGE, string $pageName = 'tidslinje', bool $withLedger = true): LengthAwarePaginator
    {
        $union = $this->activities($account);

        if ($account->customer_id !== null && $withLedger) {
            $customer = $account->customer_id;
            $union->unionAll($this->documents($customer))
                ->unionAll($this->invoices($customer))
                ->unionAll($this->payments($customer))
                ->unionAll($this->reminders($customer));
        }

        return DB::query()->fromSub($union, 'timeline')
            ->orderByDesc('at')
            ->orderByDesc('kind')
            ->orderByDesc('ref')
            ->paginate($perPage, ['*'], $pageName);
    }

    /** The app's time zone: dates and local timestamps are read in it, whatever the session's zone. */
    private static function zone(): string
    {
        return (string) config('app.timezone');
    }

    private function activities(Account $account): Builder
    {
        return Activity::query()->toBase()
            ->leftJoin('users', 'users.id', '=', 'crm_activities.assigned_to')
            ->leftJoin('crm_people', 'crm_people.id', '=', 'crm_activities.person_id')
            ->where('crm_activities.account_id', $account->id)
            ->select([
                DB::raw("'activity' AS kind"),
                DB::raw('crm_activities.id::text AS ref'),
                DB::raw('NULL::text AS number'),
                DB::raw('NULL::bigint AS amount_ore'),
                DB::raw('crm_activities.type::text AS status'),
                DB::raw('crm_activities.subject::text AS subject'),
                DB::raw('crm_activities.body::text AS body'),
                DB::raw('crm_activities.call_outcome::text AS outcome'),
                DB::raw('crm_activities.done_at AS done_at'),
                DB::raw('crm_activities.due_at AS due_at'),
                DB::raw('users.name::text AS user_name'),
                DB::raw('crm_people.name::text AS person_name'),
            ])
            // created_at is a local timestamp without zone (app.timezone); make it timestamptz like done_at, whatever the session's zone.
            ->selectRaw('COALESCE(crm_activities.done_at, crm_activities.created_at AT TIME ZONE ?) AS at', [self::zone()]);
    }

    private function documents(int $customerId): Builder
    {
        return SalesDocument::query()->toBase()
            ->where('customer_id', $customerId)
            ->select([
                DB::raw('LOWER(kind) AS kind'),
                DB::raw('id::text AS ref'),
                DB::raw('number::text AS number'),
                DB::raw('net_ore AS amount_ore'),
                DB::raw('status::text AS status'),
                ...$this->empty(),
            ])
            // Last in every part, so the columns of the UNION line up; the zone is a binding.
            ->selectRaw('document_date::timestamp AT TIME ZONE ? AS at', [self::zone()]);
    }

    private function invoices(int $customerId): Builder
    {
        return Invoice::query()->toBase()
            ->where('customer_id', $customerId)
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->select([
                DB::raw("CASE WHEN kind = '".Invoice::CREDIT."' THEN 'credit' ELSE 'invoice' END AS kind"),
                DB::raw('id::text AS ref'),
                DB::raw('number::text AS number'),
                DB::raw('net_ore AS amount_ore'),
                DB::raw('status::text AS status'),
                ...$this->empty(),
            ])
            // Last in every part, so the columns of the UNION line up; the zone is a binding.
            ->selectRaw('invoice_date::timestamp AT TIME ZONE ? AS at', [self::zone()]);
    }

    private function payments(int $customerId): Builder
    {
        return InvoicePayment::query()->toBase()
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->where('invoices.customer_id', $customerId)
            ->select([
                DB::raw("'payment' AS kind"),
                DB::raw('invoice_payments.invoice_id::text AS ref'),
                DB::raw('invoices.number::text AS number'),
                DB::raw('invoice_payments.amount_ore AS amount_ore'),
                DB::raw('NULL::text AS status'),
                ...$this->empty(),
            ])
            // Last in every part, so the columns of the UNION line up; the zone is a binding.
            ->selectRaw('invoice_payments.paid_on::timestamp AT TIME ZONE ? AS at', [self::zone()]);
    }

    private function reminders(int $customerId): Builder
    {
        return InvoiceReminder::query()->toBase()
            ->join('invoices', 'invoices.id', '=', 'invoice_reminders.invoice_id')
            ->where('invoices.customer_id', $customerId)
            ->select([
                DB::raw("'reminder' AS kind"),
                DB::raw('invoice_reminders.invoice_id::text AS ref'),
                DB::raw('invoices.number::text AS number'),
                DB::raw('invoice_reminders.remaining_ore AS amount_ore'),
                DB::raw('invoice_reminders.sequence::text AS status'),
                ...$this->empty(),
            ])
            // Last in every part, so the columns of the UNION line up; the zone is a binding.
            ->selectRaw('invoice_reminders.sent_on::timestamp AT TIME ZONE ? AS at', [self::zone()]);
    }

    /** @return list<Expression> the activity columns, empty for a ledger entry */
    private function empty(): array
    {
        return [
            DB::raw('NULL::text AS subject'),
            DB::raw('NULL::text AS body'),
            DB::raw('NULL::text AS outcome'),
            DB::raw('NULL::timestamptz AS done_at'),
            DB::raw('NULL::timestamptz AS due_at'),
            DB::raw('NULL::text AS user_name'),
            DB::raw('NULL::text AS person_name'),
        ];
    }
}
