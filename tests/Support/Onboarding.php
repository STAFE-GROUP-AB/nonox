<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Waitlist\WaitlistStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the onboarding tests: an approved waiting-list entry with its
 * user, a sign-in, a company and the things that count as "moved in".
 */
final class Onboarding
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: WaitlistEntry, 1: User}
     */
    public static function approved(array $attributes = [], ?CarbonInterface $approvedAt = null): array
    {
        $user = User::factory()->unverified()->create(['name' => $attributes['name'] ?? 'Kim Exempel', 'email' => $attributes['email'] ?? 'kim'.uniqid().'@example.test']);

        $entry = WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create([
            'name' => $user->name,
            'email' => $user->email,
            'company_name' => 'Exempelkaféet AB',
            'decided_at' => $approvedAt ?? now(),
            'approved_user_id' => $user->id,
            ...$attributes,
        ]);

        return [$entry->refresh(), $user];
    }

    public static function signIn(User $user, ?CarbonInterface $at = null): void
    {
        DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => 'auth.signed_in', 'created_at' => $at ?? now()]);
    }

    public static function company(User $user, ?CarbonInterface $at = null): Company
    {
        $company = Company::factory()->create(['user_id' => $user->id]);
        DB::table('teams')->where('id', $company->id)->update(['created_at' => $at ?? now()]);

        return $company->fresh();
    }

    public static function sieImport(Company $company, User $user, string $status = 'COMPLETED'): void
    {
        DB::table('sie_imports')->insert([
            'company_id' => $company->id, 'user_id' => $user->id, 'status' => $status, 'original_name' => 'x.se', 'file_path' => 'x',
            'size' => 1, 'sha256' => str_repeat('a', 64), 'report' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function registerImport(Company $company, User $user, string $status = 'COMPLETED'): void
    {
        DB::table('register_imports')->insert([
            'company_id' => $company->id, 'user_id' => $user->id, 'kind' => 'customers', 'status' => $status, 'original_name' => 'x.csv',
            'size' => 1, 'sha256' => str_repeat('b', 64), 'options' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function fortnoxConnection(Company $company, User $user): void
    {
        DB::table('fortnox_connections')->insert([
            'company_id' => $company->id, 'connected_by' => $user->id, 'access_token' => 'x', 'refresh_token' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function invoiceSent(Company $company): void
    {
        $customer = Spotlight::customer($company, 1, 'Kund AB');
        Spotlight::invoice($company, $customer, 1, 100000);
    }
}
