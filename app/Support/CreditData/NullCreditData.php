<?php

declare(strict_types=1);

namespace App\Support\CreditData;

/**
 * No credit reporting company is connected (ADR-047): the default until
 * the owner has a contract and a driver implements CreditData.
 */
final class NullCreditData implements CreditData
{
    public function available(): bool
    {
        return false;
    }

    public function name(): string
    {
        return '';
    }

    public function report(string $orgNumber): ?CreditReport
    {
        return null;
    }

    /** The installation's driver: services.credit_data.driver, none until a contract exists. */
    public static function resolve(): CreditData
    {
        $driver = config('services.credit_data.driver');

        if (is_string($driver) && $driver !== '' && class_exists($driver) && is_subclass_of($driver, CreditData::class)) {
            /** @var CreditData */
            return app($driver);
        }

        return new self;
    }
}
