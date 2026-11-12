<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which wording of the consent the photo was given under, besides when
 * (ADR-071 §7, review of M5): the text on the admin page is versioned in
 * WorkshopPeople::CONSENT_VERSION.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_people', function (Blueprint $table) {
            $table->string('photo_consent_version', 20)->nullable();
        });
    }
};
