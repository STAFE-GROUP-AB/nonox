<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\Actions\Company\CreateCompany;
use App\Models\User;
use App\Support\FirstRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Creates the very first user and their company in one transaction. The email
 * address must already have been confirmed with a one-time code.
 */
class CompleteFirstRun
{
    public function __construct(private CreateCompany $createCompany) {}

    /**
     * @param  array<string, mixed>  $company
     *
     * @throws AuthorizationException
     */
    public function __invoke(string $name, string $verifiedEmail, array $company): User
    {
        return Cache::lock('first-run', 10)->block(5, function () use ($name, $verifiedEmail, $company): User {
            if (! FirstRun::allowed()) {
                throw new AuthorizationException('The first-run setup has already been completed.');
            }

            return DB::transaction(function () use ($name, $verifiedEmail, $company): User {
                $user = User::create([
                    'name' => trim($name),
                    'email' => $verifiedEmail,
                    'email_verified_at' => now(),
                ]);

                $this->createCompany->create($user, $company);

                return $user;
            });
        });
    }
}
