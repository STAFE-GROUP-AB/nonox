<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing an ÄTA on site (ADR-074 O4): the field worker's phone shows the
 * signing page only after the customer has typed a code sent to the
 * customer's own e-mail. Only the code's hash is kept; the signing link is
 * kept encrypted until the code is verified, then forgotten. The row is the
 * evidence of who presented the page and on which device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_order_on_site_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('change_order_id')->constrained('change_orders');
            $table->foreignId('signing_request_id')->constrained('crm_signing_requests');
            $table->char('code_hash', 64);
            $table->text('url')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('presented_by')->constrained('users');
            $table->string('presented_user_agent', 500)->nullable();
            $table->timestamps();
        });
    }
};
