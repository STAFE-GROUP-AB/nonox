<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Scanner;

use App\Models\Company;
use App\Models\User;

/**
 * Another module's work in the scanning app (ADR-040), such as Kök's Svinn
 * tile (ADR-073 §6). The extension names the operation kinds it runs and
 * the permission that allows them; the scanner runs them exactly once per
 * UUID like its own, and never sends a price or a cost to the phone.
 */
interface ScannerExtension
{
    /** The key in the scanner's data and its `can` flags, such as "waste". */
    public function key(): string;

    /** @return list<string> operation kinds this extension runs */
    public function kinds(): array;

    /** Whether the user may use the extension in the company (the module on and the permission held). */
    public function allows(User $user, Company $company): bool;

    /**
     * The stock locations a user who reaches Skanna only through this
     * extension may see, or null for every location.
     *
     * @return ?list<int>
     */
    public function locationIds(User $user, Company $company): ?array;

    /**
     * Reference data for the phone, without prices or costs.
     *
     * @return array<string, mixed>
     */
    public function data(User $user, Company $company): array;

    /**
     * Runs one operation inside the scanner's transaction. Throws a
     * ValidationException to reject it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(User $user, Company $company, string $kind, array $payload): array;
}
