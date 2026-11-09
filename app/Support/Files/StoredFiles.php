<?php

declare(strict_types=1);

namespace App\Support\Files;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removal of stored files that rows may share.
 *
 * One stored file can be underlag for several records: a mail in the inbox
 * becomes one or more vouchers, a supplier invoice's file and an expense
 * receipt are linked to the voucher that books them. The file is never
 * copied (AttachToVoucher::linkStoredFile), so removing one row must not
 * remove the file another row still refers to. A received document is
 * räkenskapsinformation and is kept in the form it arrived in (BFL 5 kap.
 * 6 §, 7 kap. 1–2 §§, BFNAR 2013:2 punkt 5.4) - according to the review of
 * 2026-11-02; a lawyer's review remains.
 *
 * Every deletion of a stored file that could be shared goes through
 * deleteUnreferenced(): the rows are removed first, then, in the same
 * transaction and under a lock per path, the file is deleted only when no
 * row in any table, of any company, refers to it any more.
 */
final class StoredFiles
{
    /** Every file referred to below lives on this disk. */
    public const string DISK = 'local';

    /**
     * Every column that holds a path on the disk: table => column. A file is
     * kept while any of them refers to it. StoredFilesTest checks that no
     * path column in the schema is missing here.
     *
     * @var array<string, string>
     */
    public const array REFERENCES = [
        'voucher_attachments' => 'file_path',
        'inbox_messages' => 'eml_path',
        'inbox_attachments' => 'path',
        'inbox_unassigned_messages' => 'eml_path',
        'supplier_invoices' => 'file_path',
        'expenses' => 'receipt_path',
        'payslips' => 'pdf_path',
        'imported_payslips' => 'file_path',
        'agi_submissions' => 'xml_path',
        'agreement_documents' => 'file_path',
        'collective_agreements' => 'local_agreement_path',
        'payroll_file_imports' => 'file_path',
        'sie_imports' => 'file_path',
        'bank_file_imports' => 'file_path',
        'payment_response_imports' => 'file_path',
        'supplier_payment_batches' => 'file_path',
        'archive_packages' => 'file_path',
        'teams' => 'logo_path',
        'article_images' => 'path',
        'staff_ledger_people' => 'photo_path',
        'peppol_transmissions' => 'xml_path',
        'work_order_photos' => 'path',
        'change_orders' => 'decided_document_path',
        'boardroom_minutes' => 'pdf_path',
    ];

    /**
     * Deletes the files no row refers to any more, once the surrounding
     * transaction commits. Call it after the rows that referred to the files
     * are deleted, inside the same transaction. Paths still referred to,
     * by this or any other company, are kept.
     *
     * @param  iterable<?string>  $paths
     * @return list<string> the paths that are deleted on commit
     */
    public static function deleteUnreferenced(iterable $paths): array
    {
        $paths = self::clean($paths);

        if ($paths === []) {
            return [];
        }

        return DB::transaction(function () use ($paths): array {
            foreach ($paths as $path) {
                self::lock($path);
            }

            $orphans = array_values(array_diff($paths, self::referenced($paths)));

            if ($orphans !== []) {
                // After commit: a rolled back removal leaves both the row and its file.
                DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($orphans));
            }

            return $orphans;
        });
    }

    /**
     * Holds the path's lock until the transaction ends, so a link to the file
     * and the check before deleting it never interleave.
     */
    public static function lock(string $path): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["stored-file:{$path}"]);
    }

    /**
     * The given paths that some row refers to. Plain query builder on
     * purpose: the check spans every company, so a file another company
     * refers to is never removed (CompanyScope does not apply here).
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function referenced(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $query = null;

        foreach (self::REFERENCES as $table => $column) {
            $part = DB::table($table)->select("{$column} as path")->whereIn($column, $paths);
            $query = $query instanceof Builder ? $query->union($part) : $part;
        }

        /** @var Builder $query */
        return array_values(array_map('strval', $query->pluck('path')->all()));
    }

    /**
     * @param  iterable<?string>  $paths
     * @return list<string> unique, non-empty, sorted so locks are taken in one order
     */
    private static function clean(iterable $paths): array
    {
        $clean = [];

        foreach ($paths as $path) {
            if ($path !== null && $path !== '') {
                $clean[$path] = $path;
            }
        }

        $clean = array_values($clean);
        sort($clean);

        return $clean;
    }
}
