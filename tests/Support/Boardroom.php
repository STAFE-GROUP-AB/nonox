<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\Actions\RecordShareEvent;
use App\Modules\Boardroom\Actions\SaveShareholder;
use App\Modules\Boardroom\BoardroomModule;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Modules\Boardroom\Models\Shareholder;
use App\Modules\ModuleManager;
use App\Support\Luhn;

/**
 * Share register test setup. Every person and company here is synthetic;
 * personal numbers are Luhn-valid but made up.
 */
final class Boardroom
{
    /** A synthetic, Luhn-valid personal number from eleven digits (ÅÅÅÅMMDDNNN). */
    public static function personalNumber(string $first11 = '19800101123'): string
    {
        return $first11.Luhn::checkDigit(substr($first11, 2));
    }

    public static function enable(Company $company, User $owner): void
    {
        app(ModuleManager::class)->enable(BoardroomModule::KEY, $company, $owner);
    }

    /** @param array<string, mixed> $overrides */
    public static function person(User $owner, Company $company, string $name = 'Anna Andersson', string $first11 = '19800101123', array $overrides = []): Shareholder
    {
        return app(SaveShareholder::class)($owner, $company, [
            'kind' => 'PERSON',
            'name' => $name,
            'personal_number' => self::personalNumber($first11),
            'address_line1' => 'Testvägen 1',
            'postal_code' => '111 22',
            'city' => 'Teststad',
            ...$overrides,
        ]);
    }

    public static function entity(User $owner, Company $company, string $name = 'Testholding AB', string $org = '556036-0793'): Shareholder
    {
        return app(SaveShareholder::class)($owner, $company, [
            'kind' => 'ENTITY',
            'name' => $name,
            'org_number' => $org,
            'address_line1' => 'Industrigatan 2',
            'postal_code' => '222 33',
            'city' => 'Exempelby',
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function record(User $user, Company $company, array $data): ShareEvent
    {
        return app(RecordShareEvent::class)($user, $company, $data);
    }

    /** A formation of shares 1–$to for one holder, 25 000 kr share capital by default. */
    public static function formation(User $user, Company $company, Shareholder $holder, int $to = 1000, int $capitalOre = 2_500_000, string $date = '2026-03-02'): ShareEvent
    {
        return self::record($user, $company, [
            'kind' => 'FORMATION', 'effective_date' => $date, 'share_from' => 1, 'share_to' => $to,
            'to_holder_id' => $holder->id, 'capital_change_ore' => $capitalOre,
        ]);
    }
}
