<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp\Http;

use App\Models\Company;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Models\ConnectSftpKey;
use App\Modules\Connect\Sftp\SftpAccess;
use App\Modules\Connect\Sftp\SftpConfig;
use App\Modules\Connect\Sftp\SftpUser;
use App\Modules\Connect\Sftp\SshPublicKey;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * SFTPGo's external authentication hook (SFTPGo v2.7, docs/external-auth;
 * ADR-032, "SFTP"). SFTPGo POSTs {username, ip, protocol, password,
 * public_key, keyboard_interactive, tls_cert, user?} and expects 200 with
 * the user as JSON to let the person in; any other status refuses the login.
 *
 * Only a public key over SSH is accepted. The company is found from the
 * generated user name alone and the key must be one of that company's
 * active keys, so a key never opens another company's home. Everything the
 * account depends on is checked again here (SftpAccess), on every login.
 */
final class AuthHookController
{
    public function __invoke(Request $request): Response
    {
        $username = $this->text($request, 'username');
        $ip = $this->text($request, 'ip');

        $refusal = match (true) {
            $this->text($request, 'protocol') !== 'SSH' => 'protocol',
            $this->text($request, 'password') !== '', $this->text($request, 'keyboard_interactive') !== '', $this->text($request, 'tls_cert') !== '' => 'login_method',
            preg_match(SftpConfig::USERNAME_PATTERN, $username) !== 1 => 'unknown_user',
            default => null,
        };

        $key = SshPublicKey::parse($this->text($request, 'public_key'));

        if ($refusal !== null || $key === null) {
            return $this->deny();
        }

        // The hook is called without a company: it is found from the generated user name, never from anything else the client sends.
        $account = CompanyScope::bypass(fn (): ?ConnectSftpAccount => ConnectSftpAccount::query()->with('responsible')->where('username', $username)->first());

        if (! $account instanceof ConnectSftpAccount) {
            return $this->deny();
        }

        $company = Company::query()->find($account->company_id);

        if (! $company instanceof Company) {
            return $this->deny();
        }

        $stored = $this->matchingKey($company, $account, $key);
        $reason = SftpAccess::refusal($account, $company, $ip)
            ?? ($stored === null ? 'unknown_key' : null)
            ?? $this->homeProblem($company, $account);

        if ($reason !== null || $stored === null) {
            $this->auditOnce($company, $account, 'connect.sftp.login_refused', $ip, ['reason' => $reason, 'fingerprint' => $key->fingerprint()]);

            return $this->deny();
        }

        CurrentCompany::run($company, function () use ($account, $stored, $ip): void {
            $now = now();
            $stored->forceFill(['last_used_at' => $now, 'last_used_ip' => $ip])->save();
            $account->forceFill(['last_login_at' => $now, 'last_login_ip' => $ip])->save();
        });

        $this->auditOnce($company, $account, 'connect.sftp.login', $ip, ['key_id' => $stored->id, 'fingerprint' => $stored->fingerprint]);

        return new JsonResponse(SftpUser::for($account, $key));
    }

    /** One of the account's active keys, when it is exactly the key presented. */
    private function matchingKey(Company $company, ConnectSftpAccount $account, SshPublicKey $key): ?ConnectSftpKey
    {
        $stored = CurrentCompany::run($company, fn (): ?ConnectSftpKey => ConnectSftpKey::query()
            ->active()
            ->where('account_id', $account->id)
            ->where('fingerprint', $key->fingerprint())
            ->first());

        return $stored instanceof ConnectSftpKey && hash_equals($stored->public_key, $key->line()) ? $stored : null;
    }

    private function homeProblem(Company $company, ConnectSftpAccount $account): ?string
    {
        if (SftpConfig::ensureHome($account->username)) {
            return null;
        }

        Log::warning('SFTP home could not be created; check NONOX_SFTP_ROOT and its permissions.', ['company_id' => $company->id]);

        return 'home_unavailable';
    }

    /**
     * Logins and refused logins are recorded at most once per ten minutes
     * per account, address and kind, so a client that reconnects every
     * minute does not fill the log.
     *
     * @param  array<string, mixed>  $payload
     */
    private function auditOnce(Company $company, ConnectSftpAccount $account, string $action, string $ip, array $payload): void
    {
        if ($company->isArchived()) {
            return;
        }

        $key = "connect-sftp-audit:{$action}:{$account->id}:".hash('sha256', $ip.'|'.json_encode($payload));

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return;
        }

        RateLimiter::hit($key, 600);

        CurrentCompany::run($company, fn () => Audit::record($action, $account, [...$payload, 'username' => $account->username, 'client_ip' => $ip], $company));
    }

    private function text(Request $request, string $field): string
    {
        $value = $request->json($field);

        return is_string($value) ? $value : '';
    }

    private function deny(): Response
    {
        return response('', 403);
    }
}
