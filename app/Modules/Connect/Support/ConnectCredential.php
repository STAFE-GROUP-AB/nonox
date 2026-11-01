<?php

declare(strict_types=1);

namespace App\Modules\Connect\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Who is calling the API on this request (ADR-032): an app through an OAuth
 * grant, or a script through an API key. Always exactly one company. The
 * user is the person who granted access or created the key; the request
 * acts with their current permissions, limited to the scopes.
 */
final readonly class ConnectCredential
{
    public const string OAUTH = 'oauth';

    public const string API_KEY = 'api_key';

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $kind,
        public string $id,
        public Company $company,
        public User $user,
        public array $scopes,
        public string $name,
    ) {}

    /** Stable key for rate limits and idempotency: "grant:<uuid>" or "key:<id>". */
    public function key(): string
    {
        return ($this->kind === self::OAUTH ? 'grant:' : 'key:').$this->id;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Whether a permission check may succeed while this credential is in
     * use: only for its own company and only for a granted scope. The user's
     * own permission is checked separately, every time.
     */
    public function permits(mixed $team, string $permission): bool
    {
        return $team instanceof Company && $team->id === $this->company->id && $this->hasScope($permission);
    }

    /** @return array{kind: string, id: string, name: string} for the audit log */
    public function describe(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id, 'name' => $this->name];
    }
}
