<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

use App\Modules\Connect\Support\ConnectAccess;
use App\Support\Audit\Audit;
use Generator;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * tools/call with an audit entry for the calls that never reach a tool
 * (ADR-063): a name that does not exist, or a tool the credential may not
 * use. The tool name is logged only when it is one of NONOX's own, never
 * the free text a client sent. Calls that reach a tool are logged by
 * ApiTool with their outcome.
 */
final class AuditedCallTool extends CallTool
{
    /**
     * @return JsonRpcResponse|Generator<JsonRpcResponse>
     *
     * @throws JsonRpcException
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        try {
            return parent::handle($request, $context);
        } catch (JsonRpcException $exception) {
            $credential = ConnectAccess::current();
            $name = $request->get('name');

            if ($credential !== null) {
                Audit::record('mcp.tool_refused', null, [
                    'tool' => is_string($name) && in_array($name, McpTools::names(), true) ? $name : null,
                    'reason' => is_string($name) && in_array($name, McpTools::names(), true) ? 'not_allowed' : 'unknown_tool',
                    'credential' => ['kind' => $credential->kind, 'id' => $credential->id],
                    'request_id' => request()->attributes->get('connect.request_id'),
                ], $credential->company, $credential->user);
            }

            throw $exception;
        }
    }
}
