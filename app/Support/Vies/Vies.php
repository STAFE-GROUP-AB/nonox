<?php

declare(strict_types=1);

namespace App\Support\Vies;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The European Commission's VIES service, which answers whether a VAT
 * number is registered in its member state. Uses the public REST API
 * (POST check-vat-number); the base URL is config('services.vies.url').
 *
 * The requester's own VAT number is sent along, so VIES returns a
 * consultation number (requestIdentifier) that proves the check was made.
 */
class Vies
{
    /**
     * @return array{valid: bool, name: ?string, address: ?string, request_identifier: ?string}
     *
     * @throws RuntimeException when VIES or the member state's register cannot answer
     */
    public function check(string $vatNumber, ?string $requesterVatNumber = null): array
    {
        $vatNumber = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $vatNumber));
        $requester = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $requesterVatNumber));

        try {
            $response = Http::baseUrl((string) config('services.vies.url'))
                ->acceptJson()
                ->timeout(15)
                ->post('check-vat-number', array_filter([
                    'countryCode' => substr($vatNumber, 0, 2),
                    'vatNumber' => substr($vatNumber, 2),
                    'requesterMemberStateCode' => $requester === '' ? null : substr($requester, 0, 2),
                    'requesterNumber' => $requester === '' ? null : substr($requester, 2),
                ]))
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException('VIES did not answer.', 0, $exception);
        }

        if (! is_bool($response->json('valid'))) {
            throw new RuntimeException('VIES could not check the number: '.json_encode($response->json('errorWrappers') ?? $response->json()));
        }

        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' && trim($value) !== '---' ? trim($value) : null;

        return [
            'valid' => (bool) $response->json('valid'),
            'name' => $text($response->json('name')),
            'address' => $text($response->json('address')),
            'request_identifier' => $text($response->json('requestIdentifier')),
        ];
    }
}
