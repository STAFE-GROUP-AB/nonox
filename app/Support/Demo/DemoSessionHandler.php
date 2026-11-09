<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * The session handler of a guest (ADR-077): the database handler, except that
 * it stores neither the IP address nor the browser's name. The demo database
 * is meant to hold no personal data, and the visitor's address is only ever
 * a keyed hash in the rate limit.
 */
final class DemoSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function addRequestInformation(&$payload)
    {
        $payload = array_merge($payload, ['ip_address' => null, 'user_agent' => null]);

        return $this;
    }
}
