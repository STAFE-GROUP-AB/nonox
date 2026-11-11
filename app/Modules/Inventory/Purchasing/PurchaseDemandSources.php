<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

/**
 * The demand sources the purchase proposal adds up (ADR-071 §5). A singleton:
 * Lager registers open customer orders; manufacturing (M4) registers the
 * components of its open production orders as demand, and their finished
 * goods as supply.
 */
final class PurchaseDemandSources
{
    /** @var array<string, PurchaseDemandSource> */
    private array $sources = [];

    /** @var array<string, PurchaseSupplySource> */
    private array $supplies = [];

    public function __construct()
    {
        $this->register(new OpenCustomerOrderDemand);
    }

    public function register(PurchaseDemandSource $source): void
    {
        $this->sources[$source::class] = $source;
    }

    /** @return list<PurchaseDemandSource> */
    public function all(): array
    {
        return array_values($this->sources);
    }

    public function registerSupply(PurchaseSupplySource $supply): void
    {
        $this->supplies[$supply::class] = $supply;
    }

    /** @return list<PurchaseSupplySource> */
    public function supplies(): array
    {
        return array_values($this->supplies);
    }
}
