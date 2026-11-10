<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A stable key per quote or order row (ADR-071 §4). Rows are written anew
 * every time the document is saved, so their ids change; the key does not.
 * The manufacturing module hangs a row's bill of materials copy on it, and
 * a quote's keys follow the rows into the order. Existing rows get a key
 * the next time their document is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_document_rows', function (Blueprint $table) {
            $table->uuid('line_key')->nullable();

            $table->index(['sales_document_id', 'line_key']);
        });
    }
};
