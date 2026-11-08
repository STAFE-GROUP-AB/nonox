<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * The accounts a company books asset purchases on (anskaffningskonton). A
 * debit on one of them is offered as a new asset in the register. Without a
 * choice of its own, a company gets the asset account of every category the
 * register knows (AssetCategory) except buildings and land; accumulated
 * depreciation accounts are left out, since a debit there is a disposal, not
 * a purchase.
 */
final readonly class AcquisitionAccounts
{
    /**
     * @param  list<array{0: int, 1: int}>  $ranges  inclusive from–to pairs
     */
    public function __construct(public array $ranges) {}

    public static function defaults(): self
    {
        $categories = array_filter(AssetCategory::cases(), fn (AssetCategory $category): bool => $category->isPurchaseDefault());

        return new self(array_values(array_map(fn (AssetCategory $category): array => [$category->accounts()['asset'], $category->accounts()['asset']], $categories)));
    }

    public static function for(Company $company): self
    {
        $stored = $company->asset_acquisition_accounts;

        if (! is_array($stored) || $stored === []) {
            return self::defaults();
        }

        return new self(array_values(array_map(fn (mixed $range): array => [(int) ((array) $range)[0], (int) ((array) $range)[1]], $stored)));
    }

    /**
     * Reads "1210, 1220-1227, 1250" into ranges. Empty gives the defaults.
     *
     * @throws ValidationException
     */
    public static function parse(string $text, string $field = 'accounts'): self
    {
        $ranges = [];

        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/^(\d{4})(?:[-–](\d{4}))?$/u', $part, $match) !== 1) {
                throw ValidationException::withMessages([$field => __('Skriv kontonummer med fyra siffror, till exempel 1220 eller 1220-1227.')]);
            }

            $from = (int) $match[1];
            $to = isset($match[2]) ? (int) $match[2] : $from;

            if ($from > $to || $from < 1000 || $to > 1999) {
                throw ValidationException::withMessages([$field => __('Anskaffningskonton är tillgångskonton mellan 1000 och 1999, med det lägre numret först.')]);
            }

            $ranges[] = [$from, $to];
        }

        return $ranges === [] ? self::defaults() : new self($ranges);
    }

    public function contains(int $account): bool
    {
        foreach ($this->ranges as [$from, $to]) {
            if ($account >= $from && $account <= $to) {
                return true;
            }
        }

        return false;
    }

    public function isDefault(): bool
    {
        return $this->ranges === self::defaults()->ranges;
    }

    public function format(): string
    {
        return implode(', ', array_map(fn (array $range): string => $range[0] === $range[1] ? (string) $range[0] : "{$range[0]}-{$range[1]}", $this->ranges));
    }

    /**
     * The category an account most likely belongs to: the one whose asset
     * account it is, or whose account group (the first three digits) it is
     * in; otherwise inventarier.
     */
    public static function categoryFor(int $account): AssetCategory
    {
        foreach (AssetCategory::cases() as $category) {
            if ($category->accounts()['asset'] === $account) {
                return $category;
            }
        }

        foreach (AssetCategory::cases() as $category) {
            if (intdiv($category->accounts()['asset'], 10) === intdiv($account, 10)) {
                return $category;
            }
        }

        return AssetCategory::Equipment;
    }
}
