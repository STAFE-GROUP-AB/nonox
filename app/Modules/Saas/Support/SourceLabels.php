<?php

declare(strict_types=1);

namespace App\Modules\Saas\Support;

use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRow;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Saas\Domain\LineMonth;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Stripe\Models\StripeObject;

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
        $stripe = [];

        foreach ($sources as [$type, $id]) {
            if ($type === SourceType::RecurringInvoice->value) {
                $recurring[$id] = true;
            }

            if ($type === SourceType::CrmAgreement->value && ctype_digit($id)) {
                $crm[(int) $id] = true;
            }

            if ($type === SourceType::StripeSubscription->value) {
                $stripe[$id] = true;
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

        // Stripe (ADR-080 step 2): a line is named by its product, read from the stored objects (two queries).
        if ($stripe !== []) {
            $products = [];
            $items = [];

            foreach (StripeObject::query()->where('type', StripeObject::SUBSCRIPTION)->whereIn('stripe_id', array_keys($stripe))->orderBy('id')->get(['stripe_id', 'payload']) as $object) {
                foreach ((array) ($object->payload['items']['data'] ?? []) as $item) {
                    $product = is_array($item) ? ($item['price']['product'] ?? null) : null;

                    if (is_string($product) && is_string($item['id'] ?? null)) {
                        $items["{$object->stripe_id}:{$item['id']}"] = $product;
                        $products[$product] = true;
                    }
                }
            }

            $names = [];

            foreach (StripeObject::query()->where('type', StripeObject::PRODUCT)->whereIn('stripe_id', array_keys($products))->orderBy('id')->get(['stripe_id', 'payload']) as $object) {
                $names[$object->stripe_id] = is_string($object->payload['name'] ?? null) ? $object->payload['name'] : $object->stripe_id;
            }

            foreach ($items as $key => $product) {
                $rows[SourceType::StripeSubscription->value.':'.$key] = $names[$product] ?? $product;
            }

            foreach (array_keys($stripe) as $id) {
                $agreements[SourceType::StripeSubscription->value.':'.$id] = __('Stripe-prenumeration :id', ['id' => $id]);
            }
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

        if ($type === SourceType::StripeSubscription) {
            return $this->rows["{$type->value}:{$id}:{$key}"] ?? (str_starts_with($key, 'discount:') ? __('Rabatt') : $key);
        }

        return $this->agreements["{$type->value}:{$id}"] ?? __('Avtal #:id', ['id' => $id]);
    }
}
