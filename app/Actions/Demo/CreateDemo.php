<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Models\Company;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoMode;
use App\Support\Luhn;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoCompanyBuilder;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\Demo\DemoIndustries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Makes one visitor's demo (ADR-077): a guest user with a placeholder
 * address and no password, and a fresh copy of an industry demo company
 * that the guest owns, built by the same code as the developer seeders.
 *
 * All of it happens in one transaction, so a build that fails leaves
 * nothing behind. The company gets its own organisation number from a
 * range the developer demos do not use, is flagged as a test company (so
 * every rule for test companies applies) and, last, both rows get the time
 * the demo ends. Nothing is blocked while it is built (DemoMode::seeding).
 *
 * @phpstan-import-type Demo from DemoIndustries
 */
final class CreateDemo
{
    /** Six-digit starts of organisation numbers the demos draw from; 559999 belongs to the developer seeders. */
    private const array ORG_PREFIXES = ['559992', '559993', '559994', '559995'];

    public function __construct(private readonly DemoCompanyBuilder $builder) {}

    public function __invoke(string $slug, CarbonImmutable $expiresAt): User
    {
        $demos = DemoIndustries::all();

        if (! array_key_exists($slug, DemoCatalog::industries()) || ! isset($demos[$slug])) {
            throw new InvalidArgumentException("No demo for {$slug}.");
        }

        // The web guards models; a seeder run from the command line unguards them. The Actions rely on $fillable.
        $unguarded = Model::isUnguarded();
        Model::reguard();

        try {
            return DemoMode::seeding(fn (): User => DB::transaction(fn (): User => $this->create($slug, $demos[$slug], $expiresAt)));
        } finally {
            if ($unguarded) {
                Model::unguard();
            }

        }
    }

    /**
     * @param  Demo  $demo
     */
    private function create(string $slug, array $demo, CarbonImmutable $expiresAt): User
    {
        $guest = User::query()->create([
            'name' => 'Gäst',
            'email' => 'gast-'.Str::lower(Str::random(20)).'@'.DemoMode::GUEST_DOMAIN,
            'email_verified_at' => now(),
        ]);

        $demo['org_number'] = $this->orgNumber();
        $company = $this->builder->build($slug, $guest, $demo, [], fn (string $level, string $message) => null);

        foreach (DemoExtra::for($slug) as $extra) {
            $context = $this->builder->context($slug, $demo, $guest, $company->fresh() ?? $company);
            CurrentCompany::run($company, fn () => $extra->afterAll($context));
        }

        $company->forceFill(['is_test_company' => true, 'demo_expires_at' => $expiresAt])->save();
        $guest->forceFill(['demo_expires_at' => $expiresAt, 'current_team_id' => $company->id])->save();

        return $guest;
    }

    /** A Luhn-valid organisation number nobody has, in the demos' own range. */
    private function orgNumber(): string
    {
        do {
            $nine = self::ORG_PREFIXES[array_rand(self::ORG_PREFIXES)].str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
            $number = substr($nine, 0, 6).'-'.substr($nine, 6).Luhn::checkDigit($nine);
        } while (CompanyScope::bypass(fn (): bool => Company::query()->where('org_number', $number)->exists()));

        return $number;
    }
}
