<?php

declare(strict_types=1);

namespace App\Modules\Pos\Contracts;

use App\Models\Article;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Domain\Sale;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Support\PosApiFailed;
use Carbon\CarbonImmutable;

/**
 * One till provider's API (ADR-042). Drivers translate between the till
 * and NONOX's terms; they never write to the database themselves except
 * through the connection's credentials (token refresh).
 */
interface PosDriver
{
    public function provider(): Provider;

    /**
     * Where to send the user to approve the connection.
     *
     * @param  ?string  $codeChallenge  PKCE S256 challenge, for providers that require it
     */
    public function authorizeUrl(string $state, string $redirectUri, ?string $codeChallenge): string;

    /**
     * Exchanges the code for credentials and reads who the merchant is.
     *
     * @return array{credentials: array<string, mixed>, external_org_id: string, display_name: ?string}
     *
     * @throws PosApiFailed
     */
    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier): array;

    /** Asks the till to forget the connection where its API allows it; failures are ignored. */
    public function revoke(PosConnection $connection): void;

    /**
     * The till's raw sales records in a period, oldest first.
     *
     * @return list<array<string, mixed>>
     *
     * @throws PosApiFailed
     */
    public function fetchSales(PosConnection $connection, CarbonImmutable $from, CarbonImmutable $until): array;

    /**
     * Reads one raw record; null when it is not a sale (a credit receipt, a
     * report, an unknown type).
     *
     * @param  array<string, mixed>  $record
     */
    public function parseSale(array $record): ?Sale;

    /**
     * Creates or updates the article in the till.
     *
     * @return array{product_id: string, variant_id: ?string}
     *
     * @throws PosApiFailed
     */
    public function pushProduct(PosConnection $connection, Article $article, ?PosProductLink $link): array;

    /**
     * Sends a stock change for a linked product (a delta, never a balance).
     *
     * @throws PosApiFailed
     */
    public function pushStockChange(PosConnection $connection, PosProductLink $link, int $deltaMilli, string $externalUuid): void;

    /**
     * The till's products with the codes that can match an article.
     *
     * @return list<array{product_id: string, variant_id: ?string, name: string, barcode: ?string, sku: ?string}>
     *
     * @throws PosApiFailed
     */
    public function listProducts(PosConnection $connection): array;
}
