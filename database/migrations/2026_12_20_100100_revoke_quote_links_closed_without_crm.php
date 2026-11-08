<?php

declare(strict_types=1);

use App\Actions\Signing\RevokeQuoteLinksClosedWithCrm;
use Illuminate\Database\Migrations\Migration;

/**
 * Data fix with the move of signing to core (ADR-075 §7): quote links that
 * ADR-046 had closed because CRM was off must not come back to life. See
 * RevokeQuoteLinksClosedWithCrm. Idempotent; runs once with the release.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RevokeQuoteLinksClosedWithCrm::class)();
    }
};
