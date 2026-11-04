<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM calendar (ADR-049): whether a connection is to a work or school
 * account (Google Workspace, Microsoft Entra) or a private one. A private
 * one is ended when the company stops allowing private accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_calendar_connections', function (Blueprint $table) {
            $table->boolean('organisation_account')->default(false);
        });
    }
};
