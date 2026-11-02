<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Waitlist\WaitlistStatus;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => mb_strtolower(fake()->unique()->safeEmail()),
            'phone' => null,
            'company_name' => fake()->company(),
            'org_number' => null,
            'industry' => 'bokforing-for-konsulter',
            'employees' => '1-4',
            'current_system' => 'inget',
            'wants_to_test' => 'Fakturering och moms.',
            'why_switch' => null,
            'consent_at' => now(),
            'status' => WaitlistStatus::Pending,
        ];
    }

    public function status(WaitlistStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
