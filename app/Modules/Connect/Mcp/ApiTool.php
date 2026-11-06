<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

use App\Modules\Connect\Support\ConnectAccess;
use App\Support\Audit\Audit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\JsonSchema as Schema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * One MCP tool (ADR-063): a REST route of NONOX API described for an AI
 * client. Listed only when the credential may call the route right now
 * (its scopes and the person's own permissions, the same check as the
 * route's `connect.scope` middleware), so a tool the credential cannot use
 * is neither shown nor callable. Every call is written to the audit log
 * with ids and enums only.
 */
final class ApiTool extends Tool
{
    /**
     * @param  array{
     *     route: string,
     *     kind: string,
     *     title: string,
     *     description: string,
     *     path?: array<string, array<string, mixed>>,
     *     query?: array<string, array<string, mixed>>,
     *     body?: array<string, array<string, mixed>>,
     *     required?: list<string>
     * }  $definition
     */
    public function __construct(string $name, private readonly array $definition)
    {
        $this->name = $name;
        $this->title = $definition['title'];
        $this->description = $definition['description'];
    }

    public function kind(): string
    {
        return $this->definition['kind'];
    }

    public function route(): string
    {
        return $this->definition['route'];
    }

    public function shouldRegister(): bool
    {
        $credential = ConnectAccess::current();
        $route = RouteFacade::getRoutes()->getByName($this->route());

        if ($credential === null || ! $route instanceof Route) {
            return false;
        }

        $scopes = ApiRouteCall::scopes($route);

        if ($scopes === null) {
            return true;
        }

        foreach (explode('|', $scopes) as $alternative) {
            $missing = array_filter(explode('+', $alternative), fn (string $scope): bool => ! $credential->user->hasTeamPermission($credential->company, $scope));

            if ($missing === []) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        $arguments = [
            ...($this->definition['path'] ?? []),
            ...($this->definition['query'] ?? []),
            ...($this->definition['body'] ?? []),
        ];

        if ($this->kind() === McpTools::DRAFT) {
            $arguments = ['idempotency_key' => McpTools::idempotencyArgument(), ...$arguments];
        }

        $required = $this->definition['required'] ?? [];
        $properties = [];

        foreach ($arguments as $name => $definition) {
            $property = Schema::fromArray($definition);
            $properties[$name] = in_array($name, $required, true) ? $property->required() : $property;
        }

        return $properties;
    }

    /** @return array<string, bool> MCP tool annotations: reads change nothing; a draft changes nothing that is booked or sent. */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => $this->kind() === McpTools::READ,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = $request->all();
        $path = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', array_intersect_key($arguments, $this->definition['path'] ?? []));
        $query = array_map(fn (mixed $value): mixed => is_bool($value) ? ($value ? '1' : '0') : $value, array_intersect_key($arguments, $this->definition['query'] ?? []));
        $body = $this->kind() === McpTools::DRAFT ? array_intersect_key($arguments, $this->definition['body'] ?? []) : null;
        $key = $this->kind() === McpTools::DRAFT ? (is_string($arguments['idempotency_key'] ?? null) ? $arguments['idempotency_key'] : '') : null;

        /** @var HttpRequest $outer */
        $outer = app('request');
        $result = ApiRouteCall::run($outer, $this->route(), $path, $query, $body, $key);

        $this->audit($outer, $result, $key);

        if (! $result->ok()) {
            return Response::error((string) json_encode($result->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if ($this->kind() === McpTools::DRAFT) {
            return Response::structured([...$result->body, 'approval' => $this->approval($result)]);
        }

        return $result->body === [] ? Response::text('{}') : Response::structured($result->body);
    }

    /**
     * What the person has to do: a draft is never booked or sent by an AI
     * assistant; it waits in NONOX for someone to look at it.
     *
     * @return array{required: bool, status: string, message: string, review_url: ?string}
     */
    private function approval(ApiResult $result): array
    {
        $data = is_array($result->body['data'] ?? null) ? $result->body['data'] : [];
        $id = is_string($data['id'] ?? null) ? $data['id'] : null;
        $isVoucher = $this->route() === 'connect.api.vouchers.store';

        return [
            'required' => true,
            'status' => is_string($data['status'] ?? null) ? $data['status'] : 'DRAFT',
            'message' => $isVoucher
                ? __('Utkastet är inte bokfört. En person måste granska och bokföra det i NONOX.')
                : __('Fakturan är ett utkast. En person måste granska, skapa och skicka den i NONOX.'),
            'review_url' => $id === null ? null : route($isVoucher ? 'accounting.vouchers.edit' : 'invoicing.invoices.edit', $id),
        ];
    }

    private function audit(HttpRequest $outer, ApiResult $result, ?string $key): void
    {
        $credential = ConnectAccess::current();

        if ($credential === null) {
            return;
        }

        $data = is_array($result->body['data'] ?? null) ? $result->body['data'] : [];

        Audit::record('mcp.tool_called', null, array_filter([
            'tool' => $this->name(),
            'kind' => $this->kind(),
            'status' => $result->status,
            'code' => $result->code(),
            'credential' => ['kind' => $credential->kind, 'id' => $credential->id],
            'draft_id' => $this->kind() === McpTools::DRAFT && $result->ok() && is_scalar($data['id'] ?? null) ? (string) $data['id'] : null,
            // A hash, never the client's text: only a key RequireIdempotencyKey would accept, shortened to an id.
            'idempotency_key_sha256' => $key !== null && preg_match('/^[\x21-\x7E]{1,255}$/', $key) === 1 ? substr(hash('sha256', $key), 0, 16) : null,
            'replayed' => $result->replayed ?: null,
            'request_id' => $outer->attributes->get('connect.request_id'),
        ], fn (mixed $value): bool => $value !== null), $credential->company, $credential->user);
    }
}
