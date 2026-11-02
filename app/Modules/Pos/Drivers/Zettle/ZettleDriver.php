<?php

declare(strict_types=1);

namespace App\Modules\Pos\Drivers\Zettle;

use App\Models\Article;
use App\Modules\Pos\Contracts\PosDriver;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Domain\PaymentMethod;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Domain\Sale;
use App\Modules\Pos\Domain\SaleRow;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Support\ArticlePayload;
use App\Modules\Pos\Support\Decimals;
use App\Modules\Pos\Support\PosApiFailed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Zettle (ADR-042), from Zettle's public API documentation: OAuth 2.0
 * authorization code with a client secret and refresh tokens, the Product
 * Library, Purchase v2, Inventory (movements between the merchant's
 * SUPPLIER, STORE and BIN locations), Pusher webhooks signed with
 * HMAC-SHA256 and Finance v2 for card fees. Every call is made once; the
 * queued jobs retry with backoff.
 */
class ZettleDriver implements PosDriver
{
    public const array SCOPES = ['READ:PRODUCT', 'WRITE:PRODUCT', 'READ:PURCHASE', 'READ:FINANCE'];

    /** Refresh this long before the token runs out (Zettle's access tokens live two hours). */
    private const int REFRESH_MARGIN_SECONDS = 300;

    public function provider(): Provider
    {
        return Provider::Zettle;
    }

    public function authorizeUrl(string $state, string $redirectUri, ?string $codeChallenge): string
    {
        return $this->url('oauth').'/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('services.zettle.client_id'),
            'scope' => implode(' ', self::SCOPES),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        $token = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => (string) config('services.zettle.client_id'),
            'client_secret' => (string) config('services.zettle.client_secret'),
        ]);

        $me = $this->check(Http::withToken($token['access_token'])->acceptJson()->timeout(30)->get($this->url('oauth').'/users/self'), 'users/self');
        $organisation = $me->json('organizationUuid');

        if (! is_string($organisation) || $organisation === '') {
            throw new PosApiFailed('Zettle did not name the organisation.');
        }

        return ['credentials' => $token, 'external_org_id' => $organisation, 'display_name' => null];
    }

    /**
     * Registers the webhook for new purchases (Pusher API) and keeps the
     * signing key. A destination already registered is replaced, so the
     * key is always the one stored.
     *
     * @throws PosApiFailed
     */
    public function registerWebhook(PosConnection $connection, string $destination): void
    {
        $existing = $this->check($this->client($connection)->get($this->url('pusher').'/organizations/self/subscriptions'), 'subscriptions');

        foreach ((array) $existing->json() as $subscription) {
            if (is_array($subscription) && ($subscription['destination'] ?? null) === $destination && is_string($subscription['uuid'] ?? null)) {
                $this->client($connection)->delete($this->url('pusher').'/organizations/self/subscriptions/'.$subscription['uuid']);
            }
        }

        $uuid = Uuid::uuid1()->toString();
        $response = $this->check($this->client($connection)->post($this->url('pusher').'/organizations/self/subscriptions', [
            'uuid' => $uuid,
            'transportName' => 'WEBHOOK',
            'eventNames' => ['PurchaseCreated'],
            'destination' => $destination,
            'contactEmail' => (string) config('services.zettle.contact_email', config('mail.from.address')),
        ]), 'subscribe');

        $key = $response->json('signingKey');

        if (! is_string($key) || $key === '') {
            throw new PosApiFailed('Zettle gave no signing key.');
        }

        $connection->forceFill(['credentials' => [...(array) $connection->credentials, 'signing_key' => $key, 'subscription_uuid' => $uuid]])->save();
    }

    public function revoke(PosConnection $connection): void
    {
        try {
            $subscription = $connection->credential('subscription_uuid');

            if ($subscription !== null) {
                $this->client($connection)->delete($this->url('pusher').'/organizations/self/subscriptions/'.$subscription);
            }

            $this->client($connection)->delete($this->url('oauth').'/application-connections/self');
        } catch (Throwable) {
            // Disconnecting here does not depend on Zettle answering.
        }
    }

    public function fetchSales(PosConnection $connection, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $purchases = [];
        $cursor = null;
        $limit = 500;

        do {
            $query = [
                'startDate' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
                'endDate' => $until->utc()->format('Y-m-d\TH:i:s\Z'),
                'limit' => $limit,
                'descending' => 'false',
            ];

            if ($cursor !== null) {
                $query['lastPurchaseHash'] = $cursor;
            }

            $response = $this->check($this->client($connection)->get($this->url('purchase').'/purchases/v2', $query), 'purchases');
            $page = array_values(array_filter((array) $response->json('purchases'), 'is_array'));
            array_push($purchases, ...$page);
            $next = $response->json('lastPurchaseHash');
            $moved = is_string($next) && $next !== '' && $next !== $cursor;
            $cursor = is_string($next) ? $next : null;
        } while (count($page) === $limit && $moved);

        /** @var list<array<string, mixed>> $purchases */
        return $purchases;
    }

    /**
     * A purchase from the Purchase API v2 or a PurchaseCreated payload:
     * amounts in minor units, quantities as strings, VAT per rate summed
     * from the rows. A refund has negative amounts and quantities; one that
     * arrives with positive quantities is turned around.
     *
     * TODO(verify): that rowTaxableAmount is the row's amount excluding
     * VAT after discounts. Where the rows' net and VAT do not add up to the
     * purchase amount, the sale carries a warning and the daily voucher is
     * not proposed until a person has looked.
     */
    public function parseSale(array $record): ?Sale
    {
        $id = null;

        foreach (['purchaseUUID1', 'purchaseUuid1', 'purchaseUUID', 'purchaseUuid', 'uuid'] as $key) {
            if (is_string($record[$key] ?? null) && $record[$key] !== '') {
                $id = $record[$key];

                break;
            }
        }

        $amount = Decimals::minorUnits($record['amount'] ?? null);

        if ($id === null || $amount === null || ! is_string($record['timestamp'] ?? null)) {
            return null;
        }

        $isRefund = (bool) ($record['refund'] ?? false) || $amount < 0;
        $sign = $isRefund ? -1 : 1;
        $rows = [];
        $vatTotals = [];
        $warnings = [];

        foreach (array_values(array_filter((array) ($record['products'] ?? []), 'is_array')) as $index => $product) {
            $quantity = Decimals::milli($product['quantity'] ?? '0') ?? 0;
            $quantity = $sign * abs($quantity);
            $rate = Decimals::vatBasisPoints($product['vatPercentage'] ?? null);
            $net = Decimals::minorUnits($product['rowTaxableAmount'] ?? null);

            if ($net === null) {
                $unit = Decimals::minorUnits($product['unitPrice'] ?? null) ?? 0;
                $net = intdiv($unit * abs($quantity), 1000);
                $warnings[] = 'ROW_AMOUNT_ESTIMATED';
            }

            $net = $sign * abs($net);

            if ($rate !== null) {
                $vatTotals[$rate] = ['net' => ($vatTotals[$rate]['net'] ?? 0) + $net, 'vat' => 0];
            }

            $rows[] = new SaleRow(
                lineNo: $index + 1,
                name: mb_substr((string) ($product['name'] ?? $product['variantName'] ?? __('Okänd vara')), 0, 255) ?: __('Okänd vara'),
                quantityMilli: $quantity,
                amountOre: $net,
                vatRate: $rate,
                isGoods: ($product['type'] ?? 'PRODUCT') === 'PRODUCT',
                externalProductId: self::stringOrNull($product['productUuid'] ?? null),
                externalVariantId: self::stringOrNull($product['variantUuid'] ?? null),
                barcode: self::stringOrNull($product['barcode'] ?? null),
                sku: self::stringOrNull($product['sku'] ?? null),
            );
        }

        $vat = Decimals::minorUnits($record['vatAmount'] ?? null) ?? 0;
        $vat = $sign * abs($vat);
        $vatTotals = $this->spreadVat($vatTotals, $record, $vat, $sign);

        $payments = [];
        $tips = 0;

        foreach ((array) ($record['payments'] ?? []) as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $method = PaymentMethod::fromZettle((string) ($payment['type'] ?? ''))->value;
            $paid = $sign * abs(Decimals::minorUnits($payment['amount'] ?? null) ?? 0);
            $payments[$method] = ($payments[$method] ?? 0) + $paid;
            // Zettle: gratuityAmount is included in the payment amount.
            $tips += $sign * abs(Decimals::minorUnits($payment['gratuityAmount'] ?? null) ?? 0);
        }

        $amount = $sign * abs($amount);
        $sumNet = array_sum(array_column($vatTotals, 'net'));
        $sumVat = array_sum(array_column($vatTotals, 'vat'));

        if ($sumNet + $sumVat !== $amount) {
            $warnings[] = 'TOTALS_DIFFER';
        }

        ksort($vatTotals);

        return new Sale(
            externalId: $id,
            soldAt: CarbonImmutable::parse($record['timestamp']),
            isRefund: $isRefund,
            currency: is_string($record['currency'] ?? null) ? strtoupper($record['currency']) : 'SEK',
            totalOre: $amount,
            vatOre: $vat,
            tipsOre: $tips,
            vatTotals: $vatTotals,
            payments: $payments,
            rows: $rows,
            warnings: array_values(array_unique($warnings)),
            payload: $record,
        );
    }

    public function pushProduct(PosConnection $connection, Article $article, ?PosProductLink $link): array
    {
        $productId = $link === null ? Uuid::uuid1()->toString() : $link->external_product_id;
        $variantId = $link?->external_variant_id === null ? Uuid::uuid1()->toString() : $link->external_variant_id;
        $name = mb_substr(trim($article->name), 0, 255);
        $variant = array_filter([
            'uuid' => $variantId,
            'name' => $name,
            'sku' => $article->sku ?? $article->number,
            'barcode' => ArticlePayload::barcode($article),
            'price' => ['amount' => ArticlePayload::grossOre($article), 'currencyId' => 'SEK'],
            // Zettle takes the percentage as a string.
            'vatPercentage' => (string) intdiv($article->vat_rate, 100),
        ], fn (mixed $value): bool => $value !== null);
        $payload = array_filter([
            'uuid' => $productId,
            'name' => $name,
            'description' => $article->description === null ? null : mb_substr($article->description, 0, 1024),
            'vatPercentage' => (string) intdiv($article->vat_rate, 100),
            'variants' => [$variant],
        ], fn (mixed $value): bool => $value !== null);

        if ($link === null) {
            $this->check($this->client($connection)->post($this->url('products').'/organizations/self/products', $payload), 'create product');

            return ['product_id' => $productId, 'variant_id' => $variantId];
        }

        $response = $this->client($connection)->put($this->url('products').'/organizations/self/products/v2/'.$productId, $payload);

        if ($response->status() === 412) {
            $etag = $this->check($this->client($connection)->get($this->url('products').'/organizations/self/products/'.$productId), 'product')->header('ETag');
            $response = $this->client($connection)->withHeaders(['If-Match' => $etag])->put($this->url('products').'/organizations/self/products/v2/'.$productId, $payload);
        }

        if ($response->status() === 404) {
            return $this->pushProduct($connection, $article, null);
        }

        $this->check($response, 'update product');

        return ['product_id' => $productId, 'variant_id' => $variantId];
    }

    public function pushStockChange(PosConnection $connection, PosProductLink $link, int $deltaMilli, string $externalUuid): void
    {
        if ($deltaMilli % 1000 !== 0) {
            throw new PosApiFailed('Zettle counts whole units.', 422);
        }

        if (! $link->stock_tracking) {
            $this->check($this->client($connection)->post($this->url('inventory').'/organizations/self/inventory', ['productUuid' => $link->external_product_id]), 'tracking');
            $link->forceFill(['stock_tracking' => true])->save();
        }

        $locations = $this->locations($connection);
        [$from, $to] = $deltaMilli > 0 ? [$locations['SUPPLIER'], $locations['STORE']] : [$locations['STORE'], $locations['BIN']];

        $this->check($this->client($connection)->put($this->url('inventory').'/organizations/self/inventory', [
            'changes' => [[
                'productUuid' => $link->external_product_id,
                'variantUuid' => $link->external_variant_id,
                'fromLocationUuid' => $from,
                'toLocationUuid' => $to,
                'change' => intdiv(abs($deltaMilli), 1000),
            ]],
            'externalUuid' => $externalUuid,
        ]), 'inventory');
    }

    public function listProducts(PosConnection $connection): array
    {
        $products = [];

        foreach ((array) $this->check($this->client($connection)->get($this->url('products').'/organizations/self/products'), 'products')->json() as $product) {
            if (! is_array($product) || ! is_string($product['uuid'] ?? null)) {
                continue;
            }

            foreach ((array) ($product['variants'] ?? []) as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $products[] = [
                    'product_id' => $product['uuid'],
                    'variant_id' => self::stringOrNull($variant['uuid'] ?? null),
                    'name' => (string) ($product['name'] ?? ''),
                    'barcode' => self::stringOrNull($variant['barcode'] ?? null),
                    'sku' => self::stringOrNull($variant['sku'] ?? null),
                ];

                break;
            }
        }

        return $products;
    }

    /**
     * Card fees for a period from the Finance API (liquid account, type
     * PAYMENT_FEE): a fee charged has a negative amount, a refunded fee a
     * positive one; the result is the fee as a cost (positive when charged).
     *
     * @return list<array{external_id: string, type: string, occurred_at: CarbonImmutable, amount_ore: int, payload: array<string, mixed>}>
     *
     * @throws PosApiFailed
     */
    public function fetchFees(PosConnection $connection, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $fees = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = $this->check($this->client($connection)->get($this->url('finance').'/v2/accounts/liquid/transactions', [
                'start' => $from->utc()->format('Y-m-d\TH:i:s'),
                'end' => $until->utc()->format('Y-m-d\TH:i:s'),
                'includeTransactionType' => 'PAYMENT_FEE',
                'limit' => $limit,
                'offset' => $offset,
            ]), 'finance');
            $page = array_values(array_filter((array) $response->json('data'), 'is_array'));

            foreach ($page as $transaction) {
                $amount = Decimals::minorUnits($transaction['amount'] ?? null);
                $uuid = $transaction['originatingTransactionUuid'] ?? $transaction['originatorTransactionUuid'] ?? null;

                if ($amount === null || ! is_string($uuid) || ! is_string($transaction['timestamp'] ?? null) || ($transaction['originatorTransactionType'] ?? '') !== 'PAYMENT_FEE') {
                    continue;
                }

                $fees[] = [
                    'external_id' => $uuid,
                    'type' => 'PAYMENT_FEE',
                    'occurred_at' => CarbonImmutable::parse($transaction['timestamp']),
                    'amount_ore' => -$amount,
                    'payload' => $transaction,
                ];
            }

            $offset += $limit;
        } while (count($page) === $limit);

        return $fees;
    }

    /**
     * The merchant's SUPPLIER, STORE and BIN locations, cached a day per connection.
     *
     * @return array{SUPPLIER: string, STORE: string, BIN: string}
     */
    private function locations(PosConnection $connection): array
    {
        /** @var array{SUPPLIER: string, STORE: string, BIN: string} */
        return Cache::remember("pos:zettle:locations:{$connection->id}", 86400, function () use ($connection): array {
            $found = [];

            foreach ((array) $this->check($this->client($connection)->get($this->url('inventory').'/organizations/self/locations'), 'locations')->json() as $location) {
                if (is_array($location) && is_string($location['type'] ?? null) && is_string($location['uuid'] ?? null)) {
                    $found[$location['type']] ??= $location['uuid'];
                }
            }

            foreach (['SUPPLIER', 'STORE', 'BIN'] as $type) {
                if (! isset($found[$type])) {
                    throw new PosApiFailed("Zettle has no {$type} location.");
                }
            }

            return ['SUPPLIER' => $found['SUPPLIER'], 'STORE' => $found['STORE'], 'BIN' => $found['BIN']];
        });
    }

    /**
     * Zettle's purchase has no net per rate: the VAT per rate comes from
     * groupedVatAmounts when present, else from the purchase VAT spread over
     * the rates by their share of the net (TODO(verify)).
     *
     * @param  array<int, array{net: int, vat: int}>  $vatTotals
     * @param  array<string, mixed>  $record
     * @return array<int, array{net: int, vat: int}>
     */
    private function spreadVat(array $vatTotals, array $record, int $vat, int $sign): array
    {
        $grouped = $record['groupedVatAmounts'] ?? null;

        if (is_array($grouped) && $grouped !== []) {
            foreach ($grouped as $rate => $amount) {
                $bp = Decimals::vatBasisPoints((string) $rate);
                $ore = Decimals::minorUnits($amount);

                if ($bp !== null && $ore !== null) {
                    $vatTotals[$bp] = ['net' => $vatTotals[$bp]['net'] ?? 0, 'vat' => $sign * abs($ore)];
                }
            }

            return $vatTotals;
        }

        $taxed = array_filter($vatTotals, fn (array $sums, int $rate): bool => $rate > 0, ARRAY_FILTER_USE_BOTH);

        if (count($taxed) === 1) {
            $only = (int) array_key_first($taxed);
            $vatTotals[$only] = ['net' => $taxed[$only]['net'], 'vat' => $vat];

            return $vatTotals;
        }

        // Several rates without a split: each rate's VAT from its net, the öre left over on the largest.
        $left = $vat;
        $largest = null;

        foreach ($taxed as $rate => $sums) {
            $share = intdiv($sums['net'] * $rate * 2 + ($sums['net'] >= 0 ? 10000 : -10000), 20000);
            $vatTotals[$rate] = ['net' => $sums['net'], 'vat' => $share];
            $left -= $share;
            $largest = $largest === null || abs($sums['net']) > abs($taxed[$largest]['net']) ? $rate : $largest;
        }

        if ($largest !== null) {
            $vatTotals[$largest] = ['net' => $vatTotals[$largest]['net'], 'vat' => $vatTotals[$largest]['vat'] + $left];
        }

        return $vatTotals;
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, refresh_token: ?string, expires_at: int}
     */
    private function tokenRequest(array $form): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post($this->url('oauth').'/token', $form);
        } catch (ConnectionException $exception) {
            throw new PosApiFailed('Zettle could not be reached: '.$exception->getMessage());
        }

        if ($response->status() === 400 || $response->status() === 401) {
            throw new PosApiFailed('Zettle refused the token request ('.$response->status().' '.(string) $response->json('error').').', $response->status(), credentialsRejected: true);
        }

        $this->check($response, 'token');
        $access = $response->json('access_token');

        if (! is_string($access) || $access === '') {
            throw new PosApiFailed('Zettle gave no access token.');
        }

        $refresh = $response->json('refresh_token');

        return [
            'access_token' => $access,
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : ($form['refresh_token'] ?? null),
            'expires_at' => time() + max(60, (int) ($response->json('expires_in') ?? 7200)),
        ];
    }

    /**
     * A valid access token: refreshed under a lock when it runs out within
     * five minutes. A refused refresh marks the connection as needing to be
     * connected again.
     */
    private function accessToken(PosConnection $connection): string
    {
        $expiresAt = (int) ($connection->credentials['expires_at'] ?? 0);

        if ($expiresAt - self::REFRESH_MARGIN_SECONDS > time() && $connection->credential('access_token') !== null) {
            return (string) $connection->credential('access_token');
        }

        return Cache::lock("pos:token:{$connection->id}", 30)->block(15, function () use ($connection): string {
            $connection->refresh();
            $expiresAt = (int) ($connection->credentials['expires_at'] ?? 0);

            if ($expiresAt - self::REFRESH_MARGIN_SECONDS > time() && $connection->credential('access_token') !== null) {
                return (string) $connection->credential('access_token');
            }

            $refresh = $connection->credential('refresh_token') ?? throw new PosApiFailed('No refresh token.', 401, credentialsRejected: true);

            try {
                $token = $this->tokenRequest([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh,
                    'client_id' => (string) config('services.zettle.client_id'),
                    'client_secret' => (string) config('services.zettle.client_secret'),
                ]);
            } catch (PosApiFailed $exception) {
                if ($exception->credentialsRejected) {
                    $connection->forceFill(['status' => ConnectionStatus::NeedsReconnect, 'last_error' => 'refresh refused'])->save();
                }

                throw $exception;
            }

            $connection->forceFill(['credentials' => [...(array) $connection->credentials, ...$token]])->save();

            return $token['access_token'];
        });
    }

    private function client(PosConnection $connection): PendingRequest
    {
        return Http::withToken($this->accessToken($connection))->acceptJson()->asJson()->timeout(30);
    }

    /** @throws PosApiFailed */
    private function check(Response $response, string $what): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $code = $response->json('errorType') ?? $response->json('error') ?? '';

        throw new PosApiFailed("Zettle {$what}: {$response->status()} ".(is_string($code) ? mb_substr($code, 0, 80) : ''), $response->status(), credentialsRejected: $response->status() === 401);
    }

    private function url(string $service): string
    {
        return rtrim((string) config("services.zettle.urls.{$service}"), '/');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 100) : null;
    }
}
