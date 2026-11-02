<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\ModuleManager;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\PosModule;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Kassakopplingar in tests (ADR-042): invented app credentials, the module
 * on (with Lager), a connected till and synthetic purchases. Never a real
 * merchant's data, and never a real call: Http::preventStrayRequests().
 */
final class Pos
{
    public const string SIGNING_KEY = 'test-signing-key-not-a-secret';

    public static function configure(): void
    {
        config([
            'services.zettle.client_id' => 'test-client-id',
            'services.zettle.client_secret' => 'test-client-secret',
            'services.zettle.contact_email' => 'drift@example.com',
            'services.onslip.client_id' => 'nonox-test',
            'services.onslip.environment' => 'test',
        ]);
        Http::preventStrayRequests();
    }

    /**
     * An owner with bookkeeping, Lager (warehouse HL with two bins) and the module on.
     *
     * @return array{0: User, 1: Company, 2: array{warehouse: StockLocation, zone: StockLocation, bin1: StockLocation, bin2: StockLocation}}
     */
    public static function company(): array
    {
        [$owner, $company] = Bookkeeping::company();
        Inventory::enable($company, $owner);
        $places = Inventory::locations($company, $owner);
        app(ModuleManager::class)->enable(PosModule::KEY, $company, $owner);
        CurrentCompany::set($company);

        return [$owner, $company, $places];
    }

    /** @param  array<string, mixed>  $settings */
    public static function connection(Company $company, User $owner, Provider $provider = Provider::Zettle, array $settings = [], ?int $locationId = null, string $org = ''): PosConnection
    {
        return CurrentCompany::run($company, fn (): PosConnection => PosConnection::create([
            'provider' => $provider,
            'status' => ConnectionStatus::Active,
            'external_org_id' => $org !== '' ? $org : (string) Str::uuid(),
            'credentials' => $provider === Provider::Zettle
                ? ['access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_at' => time() + 7200, 'signing_key' => self::SIGNING_KEY]
                : ['hawk_id' => 'key:nonox+token@demo.example', 'hawk_key' => 'test-hawk-key', 'realm' => 'demo.example'],
            'webhook_token' => Str::random(48),
            'settings' => ['sync_direction' => PosConnection::ONE_WAY, 'push_products' => true, 'push_stock' => $provider->supportsStock(), ...$settings],
            'location_id' => $locationId,
            'connected_by' => $owner->id,
            'connected_at' => now(),
        ]));
    }

    /**
     * A Zettle purchase (Purchase API v2 shape) with synthetic values.
     *
     * @param  list<array<string, mixed>>  $products
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function purchase(string $uuid, array $products, array $payments, int $amount, int $vat, string $timestamp = '2026-03-10T10:15:00.000+0000', array $extra = []): array
    {
        return [
            'purchaseUUID1' => $uuid,
            'amount' => $amount,
            'vatAmount' => $vat,
            'timestamp' => $timestamp,
            'currency' => 'SEK',
            'refund' => false,
            'products' => $products,
            'payments' => $payments,
            ...$extra,
        ];
    }

    /** @return array<string, mixed> */
    public static function product(string $name, string $quantity, int $net, string $vat = '25', ?string $barcode = null, ?string $productUuid = null, string $type = 'PRODUCT'): array
    {
        return array_filter([
            'name' => $name,
            'quantity' => $quantity,
            'rowTaxableAmount' => $net,
            'vatPercentage' => $vat,
            'barcode' => $barcode,
            'productUuid' => $productUuid,
            'type' => $type,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * A signed Pusher envelope as Zettle sends it.
     *
     * @param  array<string, mixed>  $purchase
     * @return array{body: string, signature: string}
     */
    public static function envelope(array $purchase, string $messageId, ?string $timestamp = null, string $key = self::SIGNING_KEY, string $event = 'PurchaseCreated'): array
    {
        $timestamp ??= now()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $payload = (string) json_encode($purchase);
        $body = (string) json_encode([
            'organizationUuid' => 'org-test',
            'messageId' => $messageId,
            'eventName' => $event,
            'timestamp' => $timestamp,
            'payload' => $payload,
        ]);

        return ['body' => $body, 'signature' => hash_hmac('sha256', $timestamp.'.'.$payload, $key)];
    }
}
