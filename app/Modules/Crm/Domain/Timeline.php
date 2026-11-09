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
use App\Modules\Crm\Models\Person;
use Closure;
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
 *
 * The account and person pages filter it into tabs: one activity type
 * (`$only` = NOTE, CALL, …) or the ledger documents only (`$only` =
 * 'ledger'); counts() gives every tab's count in one query. A person's
 * timeline is the activities about them, at whichever account.
 */
final class Timeline
{
    public const int PER_PAGE = 20;

    /**
     * @param  bool  $withLedger  false shows the activities only (a private person's account for someone who may not see invoices)
     * @return LengthAwarePaginator<int, object>
     */
    public function for(Account $account, int $perPage = self::PER_PAGE, string $pageName = 'tidslinje', bool $withLedger = true, ?string $only = null): LengthAwarePaginator
    {
        $customer = $withLedger ? $account->customer_id : null;
        $parts = [];

        if ($only !== 'ledger') {
            $parts[] = $this->activities(fn (Builder $query) => $query->where('crm_activities.account_id', $account->id), $only);
        }

        if ($customer !== null && ($only === null || $only === 'ledger')) {
            $parts = [...$parts, $this->documents($customer), $this->invoices($customer), $this->payments($customer), $this->reminders($customer)];
        }

        return $this->page($parts, $perPage, $pageName);
    }

    /**
     * The activities about a person, at any of their accounts, newest first;
     * $only is one activity type.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function forPerson(Person $person, int $perPage = self::PER_PAGE, string $pageName = 'tidslinje', ?string $only = null): LengthAwarePaginator
    {
        return $this->page([$this->activities(fn (Builder $query) => $query->where('crm_activities.person_id', $person->id), $only)], $perPage, $pageName);
    }

    /**
     * Every tab's count in one query: the activities per type, all of them,
     * and (for a customer's account, when the ledger is shown) the ledger
     * documents.
     *
     * @return array{all: int, ledger: int, types: array<string, int>}
     */
    public function counts(Account|Person $record, bool $withLedger = true): array
    {
        $customer = $record instanceof Account && $withLedger ? $record->customer_id : null;
        $column = $record instanceof Account ? 'account_id' : 'person_id';

        $rows = Activity::query()->toBase()
            ->where($column, $record->id)
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) AS total');

        $types = $rows->pluck('total', 'type')->map(fn (mixed $total): int => (int) $total)->all();
        $ledger = $customer === null ? 0 : DB::query()->fromSub(
            $this->documents($customer)->unionAll($this->invoices($customer))->unionAll($this->payments($customer))->unionAll($this->reminders($customer)),
            'ledger',
        )->count();

        return ['all' => array_sum($types) + $ledger, 'ledger' => $ledger, 'types' => $types];
    }

    /**
     * @param  list<Builder>  $parts
     * @return LengthAwarePaginator<int, object>
     */
    private function page(array $parts, int $perPage, string $pageName): LengthAwarePaginator
    {
        $union = array_shift($parts) ?? $this->activities(fn (Builder $query) => $query->whereRaw('1 = 0'), null);

        foreach ($parts as $part) {
            $union->unionAll($part);
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

    /** @param  Closure(Builder): mixed  $scope */
    private function activities(Closure $scope, ?string $type): Builder
    {
        $query = Activity::query()->toBase()
            ->leftJoin('users', 'users.id', '=', 'crm_activities.assigned_to')
            ->leftJoin('crm_people', 'crm_people.id', '=', 'crm_activities.person_id')
            ->leftJoin('crm_accounts', 'crm_accounts.id', '=', 'crm_activities.account_id')
            ->leftJoin('crm_deals', 'crm_deals.id', '=', 'crm_activities.deal_id');
        $scope($query);

        return $query
            ->when($type !== null, fn (Builder $query) => $query->where('crm_activities.type', $type))
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
                DB::raw('crm_activities.person_id AS person_id'),
                DB::raw('crm_activities.account_id AS account_id'),
                DB::raw('crm_accounts.name::text AS account_name'),
                DB::raw('crm_activities.deal_id AS deal_id'),
                DB::raw('crm_deals.title::text AS deal_title'),
                DB::raw('crm_activities.email_id AS email_id'),
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
                DB::raw('COALESCE(invoices.number::text, invoices.external_ref) AS number'),
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
                DB::raw('COALESCE(invoices.number::text, invoices.external_ref) AS number'),
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
            DB::raw('NULL::bigint AS person_id'),
            DB::raw('NULL::bigint AS account_id'),
            DB::raw('NULL::text AS account_name'),
            DB::raw('NULL::bigint AS deal_id'),
            DB::raw('NULL::text AS deal_title'),
            DB::raw('NULL::bigint AS email_id'),
        ];
    }
}
