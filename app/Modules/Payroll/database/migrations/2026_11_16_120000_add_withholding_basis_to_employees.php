<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the pay is the employee's main income (tax table) or a side
 * income, from which 30 % is withheld (SFL 11 kap. 20 § första stycket 1,
 * AUD-LON-11, #75). Existing employees keep the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('withholding_basis', 20)->default('TABLE')->after('tax_column');
        });
    }
};
