<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the paths of files that several rows can share, so the check
 * before a file is removed (StoredFiles::deleteUnreferenced) stays a lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_attachments', fn (Blueprint $table) => $table->index('file_path'));
        Schema::table('supplier_invoices', fn (Blueprint $table) => $table->index('file_path'));
        Schema::table('inbox_messages', fn (Blueprint $table) => $table->index('eml_path'));
        Schema::table('inbox_attachments', fn (Blueprint $table) => $table->index('path'));
        Schema::table('expenses', fn (Blueprint $table) => $table->index('receipt_path'));
    }
};
