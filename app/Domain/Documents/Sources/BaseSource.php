<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * The shared column layout and the search helper of the adapters.
 */
abstract class BaseSource implements DocumentSource
{
    public function subtitle(DocumentRef $row): ?string
    {
        return $row->ref === null || $row->ref === '' ? null : (string) $row->ref;
    }

    /**
     * @param  literal-string  $from  table and alias, such as "voucher_attachments as a"
     * @param  array<string, literal-string>  $columns  SQL expressions by normalized column; missing ones are null
     */
    protected function select(string $from, array $columns): Builder
    {
        return DB::query()->from(new Expression($from))->select(new Expression($this->columnsSql($columns)));
    }

    /**
     * @param  array<string, literal-string>  $columns
     * @return literal-string
     */
    protected function columnsSql(array $columns): string
    {
        $columns += [
            'parent_id' => 'null', 'title_encrypted' => 'null', 'mime' => 'null', 'size' => 'null', 'ref' => 'null',
        ];

        $sql = ["'{$this->key()}'::text as source"];
        $types = ['source_id' => 'text', 'parent_id' => 'text', 'title' => 'text', 'title_encrypted' => 'text', 'doc_date' => 'timestamp', 'mime' => 'text', 'size' => 'bigint', 'ref' => 'text'];

        foreach ($types as $column => $type) {
            $sql[] = "({$columns[$column]})::{$type} as {$column}";
        }

        return implode(', ', $sql);
    }

    /**
     * A plain substring match on a readable title.
     *
     * @param  literal-string  $expression
     */
    protected function search(Builder $query, string $expression, string $search): Builder
    {
        if ($search === '') {
            return $query;
        }

        return $query->where(new Expression($expression), 'ilike', '%'.addcslashes($search, '%_\\').'%');
    }
}
