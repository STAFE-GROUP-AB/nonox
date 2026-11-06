<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST /api/mcp (ADR-063): one JSON-RPC message per request over MCP's
 * streamable HTTP transport, answered as JSON. The request has already
 * been authenticated and bound to one company by NONOX API.
 */
final class McpEndpoint
{
    public function __invoke(Request $request): Response|StreamedResponse
    {
        $transport = new HttpTransport($request);
        $server = app()->make(NonoxMcpServer::class, ['transport' => $transport]);
        $server->start();

        return $transport->run();
    }
}
