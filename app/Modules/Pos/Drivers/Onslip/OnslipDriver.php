<?php

declare(strict_types=1);

namespace App\Modules\Pos\Drivers\Onslip;

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
use App\Modules\Pos\Support\Hawk;
use App\Modules\Pos\Support\PosApiFailed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Onslip 360 (ADR-042), from Onslip's public developer documentation: OAuth
 * authorization code with PKCE (S256) that yields Hawk credentials (the
 * access token is the Hawk id, the secret the Hawk key), every call signed
 * with Hawk, resources under ~{realm}/, the journal per till for receipts,
 * products with their VAT on the product group. Onslip documents no stock
 * API, so NONOX sends no stock to it.
 */
class OnslipDriver implements PosDriver
{
    /** Permissions asked for; never edit-records: NONOX only reads the journal. */
    public const array SCOPES = ['show-products', 'edit-products', 'show-product-groups', 'show-records', 'show-tills'];

    private const int PAGE = 500;

    public function provider(): Provider
    {
        return Provider::Onslip;
    }

    public function authorizeUrl(string $state, string $redirectUri, ?string $codeChallenge): string
    {
        return $this->web().'/oauth-authorization?'.http_build_query([
            'client_id' => (string) config('services.onslip.client_id'),
            'code_challenge_method' => 'S256',
            'code_challenge' => (string) $codeChallenge,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The code lives 60 seconds, so this runs straight from the callback.
     * TODO(verify): the token call's body (JSON with grant_type, code,
     * code_verifier, redirect_uri, client_id) follows Onslip's published
     * SDK, as the documentation page names only the response fields.
     */
    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        try {
            $response = Http::acceptJson()->asJson()->timeout(30)->post($this->api().'oauth-token.json', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => (string) $codeVerifier,
                'redirect_uri' => $redirectUri,
                'client_id' => (string) config('services.onslip.client_id'),
            ]);
        } catch (ConnectionException $exception) {
            throw new PosApiFailed('Onslip could not be reached: '.$exception->getMessage());
        }

        $this->check($response, 'token');
        $id = $response->json('access_token');
        $secret = $response->json('secret');
        $realm = $response->json('realm');

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '' || ! is_string($realm) || $realm === '') {
            throw new PosApiFailed('Onslip gave incomplete credentials.');
        }

        return [
            'credentials' => ['hawk_id' => $id, 'hawk_key' => $secret, 'realm' => $realm],
            'external_org_id' => $realm,
            'display_name' => $realm,
        ];
    }

    /**
     * Onslip documents no call that ends the token; the user removes it in
     * Onslip's Backoffice (the manual says so).
     */
    public function revoke(PosConnection $connection): void {}

    /**
     * Receipts per till in the period, using Onslip's query language on each
     * till's journal and its offset and count parameters (o, c), until the
     * X-Total-Count is reached. TODO(verify) with Onslip: that a date range
     * in q works on listRecords.
     */
    public function fetchSales(PosConnection $connection, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $tills = $this->list($connection, '/tills/', null);
        $query = 'type:receipt AND date>="'.$from->utc()->format('Y-m-d\TH:i:s.v\Z').'" AND date<"'.$until->utc()->format('Y-m-d\TH:i:s.v\Z').'"';
        $records = [];

        foreach ($tills as $till) {
            $tillId = $till['id'] ?? null;

            if (! is_int($tillId)) {
                continue;
            }

            foreach ($this->list($connection, "/journal/{$tillId}/", $query) as $record) {
                $records[] = [...$record, 'nonox_till' => $tillId];
            }
        }

        usort($records, fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        return $records;
    }

    /**
     * A journal record of type receipt: a sale, or a return (refund).
     * Credit receipts are not sales and are left out. Amounts are numbers
     * in the currency (Onslip's Money), read exactly into öre.
     *
     * TODO(verify): the sign of a return receipt's amounts and quantities;
     * a return with positive amounts is turned negative here.
     */
    public function parseSale(array $record): ?Sale
    {
        $receipt = $record['receipt'] ?? null;

        if (($record['type'] ?? null) !== 'receipt' || ! is_array($receipt) || ! is_string($record['date'] ?? null) || ! isset($record['id'])) {
            return null;
        }

        $type = $receipt['type'] ?? 'sale';

        if (! in_array($type, ['sale', 'return'], true)) {
            return null;
        }

        $total = Decimals::ore($receipt['total-amount'] ?? null);

        if ($total === null) {
            return null;
        }

        $isRefund = $type === 'return' || $total < 0;
        $sign = $isRefund ? -1 : 1;
        $rows = [];
        $vatTotals = [];
        $payments = [];
        $tips = 0;

        foreach (array_values(array_filter((array) ($receipt['items'] ?? []), 'is_array')) as $index => $item) {
            $quantity = $sign * abs(Decimals::milli($item['quantity'] ?? 1) ?? 0);
            $price = Decimals::ore($item['price'] ?? 0) ?? 0;

            $rows[] = new SaleRow(
                lineNo: $index + 1,
                name: mb_substr((string) ($item['product-name'] ?? __('Okänd vara')), 0, 255) ?: __('Okänd vara'),
                quantityMilli: $quantity,
                amountOre: intdiv($price * $quantity, 1000),
                vatRate: null,
                isGoods: ($item['type'] ?? 'goods') === 'goods',
                externalProductId: isset($item['product']) && is_int($item['product']) ? (string) $item['product'] : null,
            );
        }

        foreach (array_filter((array) ($receipt['vat-summaries'] ?? []), 'is_array') as $summary) {
            $rate = Decimals::vatBasisPoints($summary['vat-rate'] ?? null);

            if ($rate === null) {
                continue;
            }

            $vatTotals[$rate]['net'] = ($vatTotals[$rate]['net'] ?? 0) + $sign * abs(Decimals::ore($summary['net-amount'] ?? 0) ?? 0);
            $vatTotals[$rate]['vat'] = ($vatTotals[$rate]['vat'] ?? 0) + $sign * abs(Decimals::ore($summary['vat-amount'] ?? 0) ?? 0);
        }

        foreach ((array) ($receipt['payments'] ?? []) as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $method = PaymentMethod::fromOnslip((string) ($payment['method'] ?? ''))->value;
            $payments[$method] = ($payments[$method] ?? 0) + $sign * abs(Decimals::ore($payment['amount'] ?? 0) ?? 0);
            $tips += $sign * abs(Decimals::ore($payment['gratuity'] ?? 0) ?? 0);
        }

        // Change given back is part of the cash payment, not of the sale.
        $change = Decimals::ore($receipt['change'] ?? 0) ?? 0;

        if ($change !== 0) {
            $payments[PaymentMethod::Cash->value] = ($payments[PaymentMethod::Cash->value] ?? 0) - $sign * abs($change);
        }

        ksort($vatTotals);
        $warnings = [];

        if (array_sum(array_column($vatTotals, 'net')) + array_sum(array_column($vatTotals, 'vat')) !== $sign * abs($total)) {
            $warnings[] = 'TOTALS_DIFFER';
        }

        return new Sale(
            externalId: ($record['nonox_till'] ?? $record['client'] ?? '0').':'.$record['id'],
            soldAt: CarbonImmutable::parse($record['date']),
            isRefund: $isRefund,
            currency: is_string($record['currency'] ?? null) ? strtoupper($record['currency']) : 'SEK',
            totalOre: $sign * abs($total),
            vatOre: $sign * abs(Decimals::ore($receipt['total-vat-amount'] ?? 0) ?? 0),
            tipsOre: $tips,
            vatTotals: $vatTotals,
            payments: $payments,
            rows: $rows,
            warnings: $warnings,
            payload: $record,
        );
    }

    /**
     * Onslip keeps VAT on the product group, so an article is sent to the
     * group the user mapped its VAT rate to; without a mapping it is not
     * sent. Price including VAT in kronor; unchanged fields are sent again
     * with PATCH (only these fields).
     */
    public function pushProduct(PosConnection $connection, Article $article, ?PosProductLink $link): array
    {
        $group = $connection->productGroup($article->vat_rate)
            ?? throw new PosApiFailed("No product group for VAT {$article->vat_rate}.", 422);

        $body = array_filter([
            'name' => mb_substr(trim($article->name), 0, 255),
            'product-group' => $group,
            'price' => (float) Decimals::units(ArticlePayload::grossOre($article)),
            'sku' => $article->sku ?? $article->number,
            'ean' => ArticlePayload::barcode($article),
            'description' => $article->description,
        ], fn (mixed $value): bool => $value !== null);

        if ($link !== null) {
            $response = $this->send($connection, 'PATCH', '/products/'.$link->external_product_id, $body);

            if ($response->status() !== 404) {
                $this->check($response, 'update product');

                return ['product_id' => $link->external_product_id, 'variant_id' => null];
            }
        }

        $id = $this->check($this->send($connection, 'POST', '/products/', $body), 'create product')->json('id');

        if (! is_int($id) && ! is_string($id)) {
            throw new PosApiFailed('Onslip gave no product id.');
        }

        return ['product_id' => (string) $id, 'variant_id' => null];
    }

    public function pushStockChange(PosConnection $connection, PosProductLink $link, int $deltaMilli, string $externalUuid): void
    {
        throw new PosApiFailed('Onslip has no stock API.', 422);
    }

    public function listProducts(PosConnection $connection): array
    {
        return array_map(fn (array $product): array => [
            'product_id' => (string) $product['id'],
            'variant_id' => null,
            'name' => (string) ($product['name'] ?? ''),
            'barcode' => is_string($product['ean'] ?? null) && $product['ean'] !== '' ? $product['ean'] : null,
            'sku' => is_string($product['sku'] ?? null) && $product['sku'] !== '' ? $product['sku'] : null,
        ], array_values(array_filter($this->list($connection, '/products/', null), fn (array $product): bool => isset($product['id']))));
    }

    /**
     * The product groups with their VAT rate, for the mapping in the
     * connection's settings.
     *
     * @return list<array{id: int, name: string, vat_rate: ?int}>
     *
     * @throws PosApiFailed
     */
    public function productGroups(PosConnection $connection): array
    {
        $groups = [];

        foreach ($this->list($connection, '/product-groups/', null) as $group) {
            if (is_int($group['id'] ?? null)) {
                $groups[] = ['id' => $group['id'], 'name' => (string) ($group['name'] ?? ''), 'vat_rate' => Decimals::vatBasisPoints($group['vat-rate'] ?? null)];
            }
        }

        return $groups;
    }

    /**
     * A whole list resource, page by page.
     *
     * @return list<array<string, mixed>>
     */
    private function list(PosConnection $connection, string $path, ?string $query): array
    {
        $items = [];
        $offset = 0;

        do {
            $parameters = array_filter(['q' => $query, 'o' => (string) $offset, 'c' => (string) self::PAGE], fn (?string $value): bool => $value !== null);
            $response = $this->check($this->send($connection, 'GET', $path, null, $parameters), $path);
            $page = array_values(array_filter((array) $response->json(), 'is_array'));
            array_push($items, ...$page);
            $offset += count($page);
            $total = $response->header('X-Total-Count') === '' ? $offset : (int) $response->header('X-Total-Count');
        } while ($page !== [] && $offset < $total);

        /** @var list<array<string, mixed>> $items */
        return $items;
    }

    /**
     * A Hawk-signed call under the connection's realm. Query parameters are
     * separated with ";" as Onslip's own clients do, and the signature covers
     * the exact path and query sent.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $query
     */
    private function send(PosConnection $connection, string $method, string $path, ?array $body, array $query = []): Response
    {
        $id = $connection->credential('hawk_id');
        $key = $connection->credential('hawk_key');
        $realm = $connection->credential('realm') ?? $connection->external_org_id;

        if ($id === null || $key === null || $realm === null) {
            throw new PosApiFailed('The connection has no Onslip credentials.', 401, credentialsRejected: true);
        }

        $url = $this->api().'~'.rawurlencode($realm).$path;

        if ($query !== []) {
            $url .= '?'.implode(';', array_map(fn (string $name, string $value): string => rawurlencode($name).'='.rawurlencode($value), array_keys($query), $query));
        }

        $payload = $body === null ? null : (string) json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => Hawk::header($id, $key, $method, $url, $payload, $payload === null ? null : 'application/json'),
        ];

        try {
            $request = Http::withHeaders($headers)->timeout(30);

            if ($payload !== null) {
                $request = $request->withBody($payload, 'application/json');
            }

            $response = $request->send($method, $url);
        } catch (ConnectionException $exception) {
            throw new PosApiFailed('Onslip could not be reached: '.$exception->getMessage());
        }

        if ($response->status() === 401) {
            $connection->forceFill(['status' => ConnectionStatus::NeedsReconnect, 'last_error' => 'credentials refused'])->save();
        }

        return $response;
    }

    /** @throws PosApiFailed */
    private function check(Response $response, string $what): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $code = $response->json('error') ?? $response->json('code') ?? '';

        throw new PosApiFailed("Onslip {$what}: {$response->status()} ".(is_string($code) ? mb_substr($code, 0, 80) : ''), $response->status(), credentialsRejected: $response->status() === 401);
    }

    private function api(): string
    {
        return config('services.onslip.environment') === 'test' ? 'https://test.onslip360.com/v1/' : 'https://api.onslip360.com/v1/';
    }

    private function web(): string
    {
        return config('services.onslip.environment') === 'test' ? 'https://test.onslip360.com' : 'https://www.onslip360.com';
    }
}
