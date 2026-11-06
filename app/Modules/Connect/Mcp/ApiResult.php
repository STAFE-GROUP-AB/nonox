<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

use Symfony\Component\HttpFoundation\Response;

/**
 * What a REST route answered when an MCP tool ran it (ADR-063): the status,
 * the decoded JSON body, and whether the Idempotency-Key replayed a stored
 * answer.
 */
final readonly class ApiResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
        public bool $replayed,
    ) {}

    public static function from(Response $response): self
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return new self(
            $response->getStatusCode(),
            is_array($decoded) ? $decoded : [],
            $response->headers->get('Idempotent-Replayed') === 'true',
        );
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** The problem code of an error answer (RFC 9457 `code`), for the audit log. */
    public function code(): ?string
    {
        return $this->ok() ? null : (is_string($this->body['code'] ?? null) ? $this->body['code'] : 'http_'.$this->status);
    }
}
