<?php

declare(strict_types=1);

namespace App\Modules\Saas\Support;

use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRow;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Saas\Domain\LineMonth;
use App\Modules\Saas\Domain\SourceType;

/**
 * The texts behind the ledger's ids (review 7, GDPR): the ledger keeps ids,
 * amounts and enums only, so a row's description and an agreement's title are
 * read from the agreement itself when a page or a file shows them. When the
 * agreement or row is gone, a neutral label is shown ("Rad 2", "Avtal #12").
 * Three queries for any number of lines.
 */
final class SourceLabels
{
    /**
     * @param  array<string, string>  $rows  "agreement id:line key" => row description
     * @param  array<string, string>  $agreements  "type:id" => agreement label
     */
    private function __construct(private readonly array $rows, private readonly array $agreements) {}

    /**
     * @param  iterable<array{0: string, 1: string}>  $sources  [source type value, source id]
     */
    public static function for(iterable $sources): self
    {
        $recurring = [];
        $crm = [];

        foreach ($sources as [$type, $id]) {
            if ($type === SourceType::RecurringInvoice->value) {
                $recurring[$id] = true;
            }

            if ($type === SourceType::CrmAgreement->value && ctype_digit($id)) {
                $crm[(int) $id] = true;
            }
        }

        $rows = [];

        foreach (RecurringInvoiceRow::query()->whereIn('recurring_invoice_id', array_keys($recurring))->get(['recurring_invoice_id', 'position', 'description']) as $row) {
            $rows["{$row->recurring_invoice_id}:r{$row->position}"] = $row->description;
        }

        $agreements = [];

        foreach (RecurringInvoice::query()->whereIn('id', array_keys($recurring))->get(['id', 'number', 'name']) as $agreement) {
            $agreements[SourceType::RecurringInvoice->value.':'.$agreement->id] = "{$agreement->title()}: {$agreement->name}";
        }

        foreach (Agreement::query()->whereIn('id', array_keys($crm))->get(['id', 'title']) as $agreement) {
            $agreements[SourceType::CrmAgreement->value.':'.$agreement->id] = $agreement->title;
        }

        return new self($rows, $agreements);
    }

    /** @param  iterable<LineMonth>  $lines */
    public static function forLines(iterable $lines): self
    {
        $sources = [];

        foreach ($lines as $line) {
            $sources[] = [$line->sourceType->value, $line->sourceId];
        }

        return self::for($sources);
    }

    public function contract(SourceType $type, string $id): string
    {
        return $this->agreements["{$type->value}:{$id}"] ?? __('Avtal #:id', ['id' => $type === SourceType::RecurringInvoice ? substr($id, 0, 8) : $id]);
    }

    public function line(SourceType $type, string $id, string $key): string
    {
        if ($type === SourceType::RecurringInvoice) {
            return $this->rows["{$id}:{$key}"] ?? __('Rad :n', ['n' => ((int) substr($key, 1)) + 1]);
        }

        return $this->agreements["{$type->value}:{$id}"] ?? __('Avtal #:id', ['id' => $id]);
    }
}
