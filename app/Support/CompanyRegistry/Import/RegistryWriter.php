<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry\Import;

use App\Models\RegistryImport;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Writes a chunk of rows to company_registry (ADR-047): one select of the
 * rows that exist, one upsert, and the changes of watched companies. A
 * source that only completes rows (Bolagsverket) never adds one.
 *
 * Changes are recorded only for organisation numbers some company's CRM has
 * as an account (the watched set), and never for a row that is new: the
 * first load creates no changes.
 */
final class RegistryWriter
{
    /** The fields monitoring reports, in the order the task lists them. */
    public const array WATCHED_FIELDS = [
        'name', 'address_line1', 'postal_code', 'city', 'municipality_code', 'legal_form', 'sni_code',
        'scb_status', 'bolagsverket_status', 'deregistered_on', 'proceedings', 'employee_class', 'turnover_class',
    ];

    /**
     * @param  array<string, true>  $watched  organisation numbers (ten digits) any company watches
     */
    public function __construct(
        private readonly RegistrySource $source,
        private readonly RegistryImport $import,
        private readonly array $watched,
    ) {}

    /**
     * @param  list<RegistryRow>  $rows
     * @return array{written: int, changes: int}
     */
    public function write(array $rows): array
    {
        if ($rows === []) {
            return ['written' => 0, 'changes' => 0];
        }

        // The same company twice in one chunk: the last one wins.
        $byOrg = [];

        foreach ($rows as $row) {
            $byOrg[$row->orgNumber] = $row;
        }

        $existing = DB::table('company_registry')
            ->whereIn('org_number', array_keys($byOrg))
            ->get(['org_number', 'sources', ...self::WATCHED_FIELDS])
            ->keyBy('org_number');

        $now = CarbonImmutable::now();
        $values = [];
        $changes = [];

        foreach ($byOrg as $org => $row) {
            $old = $existing->get($org);

            if ($old === null && ! $this->source->createsRows()) {
                continue;
            }

            $sources = $old === null ? [] : (array) json_decode((string) $old->sources, true);
            $sources = array_values(array_unique([...$sources, $this->source->key()]));
            sort($sources);

            $attributes = array_map(fn (mixed $value): mixed => self::column($value), $row->attributes);
            $attributes['sources'] = json_encode($sources);

            if ($this->source->createsRows()) {
                $attributes['gone_at'] = null;
            }

            if ($this->source->key() === ScbBulkFile::KEY) {
                $attributes['last_import_id'] = $this->import->id;
            }

            $values[] = ['org_number' => $org, ...$attributes, 'created_at' => $now, 'updated_at' => $now];

            if ($old !== null && isset($this->watched[$org])) {
                foreach (self::WATCHED_FIELDS as $field) {
                    if (! array_key_exists($field, $attributes)) {
                        continue;
                    }

                    $before = self::comparable($old->{$field});
                    $after = self::comparable($attributes[$field]);

                    if ($before !== $after) {
                        $changes[] = [
                            'org_number' => $org,
                            'field' => $field,
                            'old_value' => $before === null ? null : mb_substr($before, 0, 500),
                            'new_value' => $after === null ? null : mb_substr($after, 0, 500),
                            'source' => $this->source->key(),
                            'import_id' => $this->import->id,
                            'detected_at' => $now,
                        ];
                    }
                }
            }
        }

        if ($values === []) {
            return ['written' => 0, 'changes' => 0];
        }

        // Every row of one source has the same columns, as an upsert needs.
        $columns = array_keys($values[0]);
        $update = array_values(array_unique(array_intersect([...$this->source->updateColumns(), 'last_import_id', 'updated_at'], $columns)));

        DB::transaction(function () use ($values, $update, $changes): void {
            $this->source->createsRows()
                ? DB::table('company_registry')->upsert($values, ['org_number'], $update)
                : $this->update($values, $update);

            if ($changes !== []) {
                DB::table('company_registry_changes')->insert($changes);
            }
        });

        return ['written' => count($values), 'changes' => count($changes)];
    }

    /** PostgreSQL types of the columns a completing source writes, for UPDATE … FROM (VALUES …). */
    private const array TYPES = [
        'registered_on' => 'date', 'deregistered_on' => 'date', 'proceedings_since' => 'date',
        'bolagsverket_fetched_at' => 'timestamptz', 'fetched_at' => 'timestamptz', 'updated_at' => 'timestamptz',
        'sources' => 'jsonb', 'deregistration_reason' => 'text', 'proceedings' => 'text',
    ];

    /**
     * Updates rows that exist, in one statement: an upsert would check the
     * NOT NULL columns of the new row (the name) before it sees the
     * conflict, and a completing source has no name.
     *
     * @param  list<array<string, mixed>>  $values
     * @param  list<string>  $columns
     */
    private function update(array $values, array $columns): void
    {
        $columns = array_values(array_filter($columns, fn (string $column): bool => isset(self::TYPES[$column])));
        $placeholders = '('.implode(', ', array_fill(0, count($columns) + 1, '?')).')';
        $bindings = [];

        foreach ($values as $row) {
            $bindings[] = $row['org_number'];

            foreach ($columns as $column) {
                $bindings[] = $row[$column] instanceof DateTimeInterface ? CarbonImmutable::instance($row[$column])->toIso8601String() : $row[$column];
            }
        }

        $set = implode(', ', array_map(fn (string $column): string => "\"{$column}\" = v.\"{$column}\"::".self::TYPES[$column], $columns));
        $names = implode(', ', ['org_number', ...array_map(fn (string $column): string => "\"{$column}\"", $columns)]);
        $rows = implode(', ', array_fill(0, count($values), $placeholders));

        DB::update("UPDATE company_registry SET {$set} FROM (VALUES {$rows}) AS v({$names}) WHERE company_registry.org_number = v.org_number", $bindings);
    }

    private static function column(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => json_encode(array_values($value)),
            is_bool($value) => $value,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->toIso8601String(),
            $value instanceof BackedEnum => $value->value,
            default => $value,
        };
    }

    private static function comparable(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }
}
