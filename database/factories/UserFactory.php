<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
            'profile_photo_path' => null,
            'current_team_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Give the user a company they own and make it their current company.
     */
    public function withCompany(?callable $callback = null): static
    {
        return $this
            ->has(
                Company::factory()
                    ->state(fn (array $attributes, ?Model $user): array => ['user_id' => $user?->getKey()])
                    ->when(is_callable($callback), $callback),
                'ownedTeams'
            )
            ->afterCreating(function (User $user): void {
                $company = $user->ownedTeams()->first();

                if ($company instanceof Company) {
                    $user->switchCompany($company);
                }
            });
    }
}
