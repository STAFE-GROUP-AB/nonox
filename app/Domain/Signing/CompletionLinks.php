<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;

/**
 * The link that replaces the attachments when the finished files are above
 * the mail limit (ADR-046 amendment, gap 5, S13): a new 256-bit token per
 * recipient, only its SHA-256 stored, valid 14 days (7 for the company's
 * copy and for a confidential envelope). The sender can close the links of
 * an envelope or send a recipient a new one. Must run inside the company.
 */
final class CompletionLinks
{
    /** A token for the mail when the files are too large to attach (decided once per completion by the caller), else null. */
    public static function issueIf(bool $tooLarge, SigningEnvelope $envelope, string $kind, string $email, string $language, ?SigningRequest $request = null): ?string
    {
        return $tooLarge ? self::issue($envelope, $kind, $email, $language, $request) : null;
    }

    public static function issue(SigningEnvelope $envelope, string $kind, string $email, string $language, ?SigningRequest $request = null): string
    {
        $token = SigningToken::generate();

        SigningDownloadLink::create([
            'company_id' => $envelope->company_id,
            'envelope_id' => $envelope->id,
            'signing_request_id' => $request?->id,
            'kind' => $kind,
            'email' => mb_strtolower($email),
            'language' => $language,
            'token_hash' => $token['hash'],
            'expires_at' => CarbonImmutable::now()->addDays(SigningDownloadLink::validDays($kind, $envelope->confidential)),
        ]);

        return $token['token'];
    }

    /** Closes every open link of an envelope (or one of them); returns how many were open. */
    public static function close(SigningEnvelope $envelope, ?SigningDownloadLink $only = null): int
    {
        return SigningDownloadLink::query()
            ->where('envelope_id', $envelope->id)
            ->whereNull('revoked_at')
            ->when($only instanceof SigningDownloadLink, fn ($query) => $query->whereKey($only?->id))
            ->update(['revoked_at' => now()]);
    }

    /** The link as it is mailed, on the signing host when there is one. */
    public static function url(string $token): string
    {
        return SigningHost::copyLink($token);
    }

    /** The link row for a token (the public page has no company: the link names it), if it is well formed and exists; expiry and closing are checked by the caller. */
    public static function find(string $token): ?SigningDownloadLink
    {
        if (! SigningToken::isWellFormed($token)) {
            return null;
        }

        $link = CompanyScope::bypass(fn (): ?SigningDownloadLink => SigningDownloadLink::query()->where('token_hash', SigningToken::hash($token))->first());

        return $link instanceof SigningDownloadLink && hash_equals($link->token_hash, SigningToken::hash($token)) ? $link : null;
    }
}
