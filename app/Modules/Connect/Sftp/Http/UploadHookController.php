<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp\Http;

use App\Models\Company;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Models\ConnectSftpUpload;
use App\Modules\Connect\Sftp\ProcessSftpUpload;
use App\Modules\Connect\Sftp\SftpAccess;
use App\Modules\Connect\Sftp\SftpConfig;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SFTPGo's upload notice (SFTPGo v2.7, docs/custom-actions; ADR-032,
 * "SFTP"). SFTPGo POSTs {action, username, path, virtual_path, file_size,
 * status, protocol, ip, session_id, timestamp, ...} after every upload. The
 * hook runs synchronously (execute_sync), so any answer but 200 makes SFTPGo
 * remove the file and report an error to the client: a refused file never
 * stays on the server.
 *
 * The company comes from the generated user name; the path SFTPGo reports
 * is not trusted, only the file name in the virtual path, which must be
 * directly in /in. The same notice twice (SFTPGo retries) is one upload.
 * The file is read by a queued job.
 */
final class UploadHookController
{
    public function __invoke(Request $request): Response
    {
        // Only finished, successful uploads are taken in; anything else changes nothing.
        if ($request->json('action') !== 'upload' || $request->json('status') !== 1) {
            return new JsonResponse(['ok' => true, 'ignored' => true]);
        }

        $username = $this->text($request, 'username');
        $virtualPath = $this->text($request, 'virtual_path');
        $ip = $this->text($request, 'ip');
        $size = $request->json('file_size');

        if (preg_match(SftpConfig::USERNAME_PATTERN, $username) !== 1) {
            return response('', 403);
        }

        // Called without a company: it is found from the generated user name, never from anything else the client sends.
        $account = CompanyScope::bypass(fn (): ?ConnectSftpAccount => ConnectSftpAccount::query()->with('responsible')->where('username', $username)->first());
        $company = $account instanceof ConnectSftpAccount ? Company::query()->find($account->company_id) : null;

        if (! $account instanceof ConnectSftpAccount || ! $company instanceof Company) {
            return response('', 403);
        }

        $fileName = $this->fileName($virtualPath);
        $refusal = SftpAccess::refusal($account, $company, $ip) ?? match (true) {
            $this->text($request, 'protocol') !== 'SSH' => 'protocol',
            $fileName === null => 'path',
            ! is_int($size) || $size < 1 || $size > SftpConfig::maxFileBytes() => 'size',
            default => null,
        };

        if ($refusal !== null || $fileName === null || ! is_int($size)) {
            $this->auditRefusal($company, $account, (string) $refusal, $virtualPath, $ip);

            return response('', $refusal === 'size' || $refusal === 'path' ? 422 : 403);
        }

        $eventKey = hash('sha256', implode("\n", [
            $username,
            $virtualPath,
            (string) $size,
            (string) $this->scalar($request, 'timestamp'),
            (string) $this->scalar($request, 'session_id'),
        ]));

        return CurrentCompany::run($company, function () use ($company, $account, $eventKey, $fileName, $size, $ip): Response {
            if (ConnectSftpUpload::query()->where('event_key', $eventKey)->exists()) {
                return new JsonResponse(['ok' => true, 'duplicate' => true]);
            }

            // Shares the hourly cap with uploads in the browser (WorkQuota).
            if (! WorkQuota::attempt($company, 'sie_upload')) {
                $this->auditRefusal($company, $account, 'quota', '/'.SftpConfig::INBOX.'/'.$fileName, $ip);

                return response('', 429);
            }

            try {
                $upload = ConnectSftpUpload::create([
                    'account_id' => $account->id,
                    'event_key' => $eventKey,
                    'file_name' => $fileName,
                    'size' => $size,
                    'ip' => $ip !== '' ? mb_substr($ip, 0, 45) : null,
                    'status' => ConnectSftpUpload::RECEIVED,
                ]);
            } catch (UniqueConstraintViolationException) {
                return new JsonResponse(['ok' => true, 'duplicate' => true]);
            }

            $account->forceFill(['last_upload_at' => now()])->save();

            Audit::record('connect.sftp.upload_received', $upload, [
                'file' => $fileName,
                'size' => $size,
                'username' => $account->username,
                'client_ip' => $ip,
            ], $company);

            ProcessSftpUpload::dispatch($company->id, $upload->id)->afterCommit();

            return new JsonResponse(['ok' => true, 'upload_id' => $upload->id]);
        });
    }

    /**
     * The file name, when the virtual path is a file directly in /in with a
     * SIE ending and no control characters; otherwise null.
     */
    private function fileName(string $virtualPath): ?string
    {
        $prefix = '/'.SftpConfig::INBOX.'/';

        if (! str_starts_with($virtualPath, $prefix)) {
            return null;
        }

        $name = substr($virtualPath, strlen($prefix));

        if ($name === '' || strlen($name) > 255 || str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1 || $name === '.' || $name === '..') {
            return null;
        }

        return SftpConfig::hasAllowedExtension($name) ? $name : null;
    }

    private function auditRefusal(Company $company, ConnectSftpAccount $account, string $reason, string $virtualPath, string $ip): void
    {
        if ($company->isArchived()) {
            return;
        }

        CurrentCompany::run($company, fn () => Audit::record('connect.sftp.upload_refused', $account, [
            'reason' => $reason,
            'path' => mb_substr($virtualPath, 0, 300),
            'username' => $account->username,
            'client_ip' => $ip,
        ], $company));
    }

    private function text(Request $request, string $field): string
    {
        $value = $request->json($field);

        return is_string($value) ? $value : '';
    }

    private function scalar(Request $request, string $field): string
    {
        $value = $request->json($field);

        return is_scalar($value) ? (string) $value : '';
    }
}
