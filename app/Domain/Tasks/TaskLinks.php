<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\Task;
use App\Models\VatReport;
use App\Models\Voucher;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The records a task may point to, by a short key (never a class name from
 * the browser). Every lookup runs under the current company's scope, so a
 * record of another company is "not found" (AGENTS.md §1.3).
 */
final class TaskLinks
{
    /** @var array<string, array{model: class-string<Model>, route: string, parameter: bool}> */
    public const array TYPES = [
        'voucher' => ['model' => Voucher::class, 'route' => 'accounting.vouchers.show', 'parameter' => true],
        'invoice' => ['model' => Invoice::class, 'route' => 'invoicing.invoices.show', 'parameter' => true],
        'supplier_invoice' => ['model' => SupplierInvoice::class, 'route' => 'payables.invoices.show', 'parameter' => true],
        'payroll_run' => ['model' => PayrollRun::class, 'route' => 'payroll.runs.show', 'parameter' => true],
        'inbox_message' => ['model' => InboxMessage::class, 'route' => 'inbox.index', 'parameter' => false],
        'vat_report' => ['model' => VatReport::class, 'route' => 'vat.index', 'parameter' => false],
    ];

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'voucher' => __('Verifikation'),
            'invoice' => __('Kundfaktura'),
            'supplier_invoice' => __('Leverantörsfaktura'),
            'payroll_run' => __('Lönekörning'),
            'inbox_message' => __('Inkorgen'),
            'vat_report' => __('Momsdeklaration'),
            default => __('Övrigt'),
        };
    }

    /**
     * The record, in the current company, or a validation error.
     */
    public static function find(string $type, string $id): Model
    {
        $model = self::TYPES[$type]['model'] ?? null;
        $record = $model === null || $id === '' ? null : $model::query()->whereKey($id)->first();

        if (! $record instanceof Model) {
            throw ValidationException::withMessages(['subject' => __('Posten som uppgiften ska kopplas till finns inte i företaget.')]);
        }

        return $record;
    }

    /** What the linked record is called on the task, in the current company; null when it is gone. */
    public static function label(Task $task): ?string
    {
        if ($task->subject_type === null || $task->subject_id === null) {
            return null;
        }

        $model = self::TYPES[$task->subject_type]['model'] ?? null;
        $record = $model === null ? null : $model::query()->whereKey($task->subject_id)->first();

        return $record instanceof Model ? self::describe($task->subject_type, $record) : null;
    }

    /** The page of the linked record, in the task's company. */
    public static function url(Task $task): ?string
    {
        $type = self::TYPES[(string) $task->subject_type] ?? null;

        if ($type === null || $task->subject_id === null) {
            return null;
        }

        return $type['parameter'] ? route($type['route'], $task->subject_id) : route($type['route']);
    }

    /**
     * The latest records of a type, to choose from in the task form.
     *
     * @return array<string, string> id => label
     */
    public static function options(string $type, int $limit = 40): array
    {
        $model = self::TYPES[$type]['model'] ?? null;

        if ($model === null) {
            return [];
        }

        /** @var Collection<int, Model> $records */
        $records = match ($type) {
            'voucher' => Voucher::query()->with('series')->orderByDesc('voucher_date')->limit($limit)->get(),
            'invoice' => Invoice::query()->with('customer')->orderByDesc('invoice_date')->limit($limit)->get(),
            'supplier_invoice' => SupplierInvoice::query()->with('supplier')->orderByDesc('invoice_date')->limit($limit)->get(),
            'payroll_run' => PayrollRun::query()->orderByDesc('payment_date')->limit($limit)->get(),
            'inbox_message' => InboxMessage::query()->orderByDesc('received_at')->limit($limit)->get(),
            'vat_report' => VatReport::query()->orderByDesc('period_start')->limit($limit)->get(),
            default => collect(),
        };

        return $records->mapWithKeys(fn (Model $record): array => [(string) $record->getKey() => self::describe($type, $record)])->all();
    }

    private static function describe(string $type, Model $record): string
    {
        return match (true) {
            $record instanceof Voucher => $record->number === null
                ? __('Verifikation (utkast) :date', ['date' => $record->voucher_date->toDateString()])
                : __('Verifikation :series:number', ['series' => $record->series->code ?? '', 'number' => $record->number]),
            $record instanceof Invoice => $record->number === null
                ? __('Kundfaktura (utkast) :date', ['date' => $record->invoice_date->toDateString()])
                : __('Kundfaktura :number', ['number' => $record->number]),
            $record instanceof SupplierInvoice => __('Leverantörsfaktura :number, :supplier', ['number' => $record->number ?? '–', 'supplier' => $record->supplierName()]),
            $record instanceof PayrollRun => __('Lönekörning :date', ['date' => $record->payment_date->toDateString()]),
            $record instanceof InboxMessage => __('E-post: :subject', ['subject' => $record->subject ?? __('(inget ämne)')]),
            $record instanceof VatReport => __('Momsdeklaration :from – :to', ['from' => $record->period_start->toDateString(), 'to' => $record->period_end->toDateString()]),
            default => self::typeLabel($type),
        };
    }
}
