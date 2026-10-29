<?php

declare(strict_types=1);

namespace Tests\Tenancy\Fixtures;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company-scoped model that exists only in tests, so the tenancy harness can
 * be proven before the first real company-scoped table exists. Its table is
 * created inside the test transaction, which PostgreSQL rolls back.
 */
class TenantNote extends Model
{
    use BelongsToCompany;

    protected $table = 'tenancy_test_notes';

    /** @var list<string> */
    protected $fillable = ['title', 'company_id'];

    public static function createTable(): void
    {
        Schema::create('tenancy_test_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams');
            $table->string('title');
            $table->timestamps();
        });
    }
}
