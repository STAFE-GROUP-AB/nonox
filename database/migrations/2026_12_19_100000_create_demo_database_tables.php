<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Try without registering (ADR-077, addendum 2026-11-08): the tables only the
 * demo database has. `php artisan migrate --database=demo` runs every
 * migration of NONOX there, so a demo has the real schema with its real
 * triggers, and this one adds what a demo needs besides: its cache, its queue
 * and the list of demos with the time each ends.
 *
 * It does nothing on any other connection. The main database is not touched,
 * and none of the immutability triggers has an exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getName() !== 'demo') {
            return;
        }

        Schema::create('demo_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('company_id')->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
};
