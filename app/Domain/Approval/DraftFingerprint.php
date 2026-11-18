<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use Illuminate\Support\Facades\DB;

/**
 * The version of a draft that an approval covers (ADR-081 E21). A manual
 * voucher or a credit invoice stays a draft while it waits, and a draft can
 * be saved again at any time, so an approval is bound to what the draft
 * said when it was approved: a SHA-256 over the draft's own columns and its
 * rows (and, for a voucher, the SHA-256 of each attachment), read from the
 * database so the same content always gives the same value.
 *
 * Row ids and timestamps are left out: the editors save every row again on
 * each save, so saving an unchanged draft keeps its fingerprint, while any
 * change to an amount, account, dimension, date, text or attachment gives a
 * new one. The approval subject id is "{draft id}.{fingerprint}", so a new
 * version is a new subject and an approval never carries over to something
 * nobody approved.
 *
 * Read in a fixed number of queries for any number of drafts.
 */
final class DraftFingerprint
{
    public const int LENGTH = 24;

    /** The invoice columns that make up a credit invoice's content. */
    private const array INVOICE_COLUMNS = [
        'customer_id', 'kind', 'credits_invoice_id', 'invoice_date', 'due_date', 'delivery_date',
        'your_reference', 'our_reference', 'notes', 'net_ore', 'vat_ore', 'rounding_ore', 'total_ore',
        'reverse_charge', 'tax_reduction', 'tax_reduction_ore', 'property_designation', 'foreign_sale',
        'invoice_currency', 'exchange_rate_micros', 'foreign_total_minor', 'labour_hours_milli', 'work_description',
    ];

    private const array VOUCHER_COLUMNS = ['series_id', 'voucher_date', 'description', 'source', 'corrects_voucher_id'];

    private const array ROW_SKIP = ['id', 'voucher_id', 'invoice_id', 'company_id', 'created_at', 'updated_at'];

    /**
     * Three queries: the vouchers, their rows and their attachments.
     *
     * @param  list<string>  $ids
     * @return array<string, string> voucher id => fingerprint (missing ids are left out)
     */
    public static function vouchers(int $companyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $headers = DB::table('vouchers')->where('company_id', $companyId)->whereIn('id', $ids)->get(['id', ...self::VOUCHER_COLUMNS]);
        $rows = DB::table('voucher_rows')->where('company_id', $companyId)->whereIn('voucher_id', $ids)->orderBy('id')->get()->groupBy('voucher_id');
        $files = DB::table('voucher_attachments')->where('company_id', $companyId)->whereIn('voucher_id', $ids)->orderBy('sha256')->get(['voucher_id', 'sha256'])->groupBy('voucher_id');

        $result = [];

        foreach ($headers as $header) {
            $id = (string) $header->id;

            $result[$id] = self::hash([
                'header' => array_diff_key((array) $header, ['id' => true]),
                'rows' => self::rows($rows->get($id, collect())->all()),
                'attachments' => $files->get($id, collect())->pluck('sha256')->all(),
            ]);
        }

        return $result;
    }

    /**
     * Two queries: the invoices and their rows.
     *
     * @param  list<string>  $ids
     * @return array<string, string> invoice id => fingerprint (missing ids are left out)
     */
    public static function invoices(int $companyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $headers = DB::table('invoices')->where('company_id', $companyId)->whereIn('id', $ids)->get(['id', ...self::INVOICE_COLUMNS]);
        $rows = DB::table('invoice_rows')->where('company_id', $companyId)->whereIn('invoice_id', $ids)->orderBy('position')->orderBy('id')->get()->groupBy('invoice_id');

        $result = [];

        foreach ($headers as $header) {
            $id = (string) $header->id;

            $result[$id] = self::hash([
                'header' => array_diff_key((array) $header, ['id' => true]),
                'rows' => self::rows($rows->get($id, collect())->all()),
            ]);
        }

        return $result;
    }

    /** "{id}.{fingerprint}" */
    public static function subjectId(string $id, string $fingerprint): string
    {
        return "{$id}.{$fingerprint}";
    }

    /**
     * The draft id and fingerprint of a subject id, or null when it is not one.
     *
     * @return ?array{0: string, 1: string}
     */
    public static function parse(string $subjectId): ?array
    {
        if (preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([0-9a-f]{'.self::LENGTH.'})$/', $subjectId, $match) !== 1) {
            return null;
        }

        return [$match[1], $match[2]];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        return array_values(array_map(function (mixed $row): array {
            $values = array_diff_key((array) $row, array_flip(self::ROW_SKIP));
            ksort($values);

            return $values;
        }, $rows));
    }

    /** @param  array<string, mixed>  $payload */
    private static function hash(array $payload): string
    {
        ksort($payload['header']);

        return substr(hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, self::LENGTH);
    }
}
