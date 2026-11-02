<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp\Http;

use App\Modules\Connect\Sftp\SftpConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only the installation's SFTPGo call the SFTP hooks (ADR-032,
 * "SFTP"). SFTPGo cannot sign a request body, so it sends a shared secret
 * as a bearer token (its http.headers setting, limited to the hook URLs);
 * it is compared in constant time and never logged. Optionally only from
 * the addresses in NONOX_SFTPGO_HOOK_IPS.
 *
 * Fails closed: without a configured secret the hooks do not exist (404),
 * and anything unexpected is refused before a company is looked up.
 */
final class AuthenticateSftpGo
{
    /** A hook body is a few hundred bytes; SFTPGo's own user JSON may come along on login. */
    private const int MAX_BODY_BYTES = 65_536;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = SftpConfig::secret();

        if ($secret === null || ! SftpConfig::available()) {
            return $this->plain(404);
        }

        $allowedIps = SftpConfig::hookIps();

        if ($allowedIps !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowedIps)) {
            return $this->plain(404);
        }

        $presented = (string) $request->bearerToken();

        if ($presented === '' || ! hash_equals($secret, $presented)) {
            return $this->plain(401);
        }

        if (strlen((string) $request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->plain(413);
        }

        if (! $request->isJson() || ! is_array(json_decode((string) $request->getContent(), true))) {
            return $this->plain(400);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function plain(int $status): Response
    {
        return response('', $status, ['Cache-Control' => 'no-store']);
    }
}
