<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent an invitation, so that the pending invitations of someone who is
 * removed from the company, or loses members:manage, are withdrawn with them
 * (security review 2026-11-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
