<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

use Laravel\Mcp\Server\McpServiceProvider as PackageServiceProvider;

/**
 * laravel/mcp, registered by NONOX API instead of package discovery
 * (composer.json "dont-discover", ADR-063). The package's own provider
 * would add an `mcp:use` scope to Passport (Connect's scopes are AGENTS.md
 * §4 permission keys and nothing else), push two middleware into every
 * request and load routes/ai.php. NONOX needs none of that: the one MCP
 * endpoint is registered by ConnectServiceProvider behind Connect's own
 * authentication. What is kept: the Registrar, the binding of the tool
 * arguments to Laravel\Mcp\Request, the views and the make: commands.
 */
final class McpServiceProvider extends PackageServiceProvider
{
    protected function registerMcpScope(): void {}

    protected function registerGlobalMiddleware(): void {}

    protected function registerRoutes(): void {}
}
