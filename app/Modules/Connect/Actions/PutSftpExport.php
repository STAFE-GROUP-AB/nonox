<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Actions\Accounting\ExportSie;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Sftp\SftpAccess;
use App\Modules\Connect\Sftp\SftpConfig;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Puts a SIE4 export of the current fiscal year in the SFTP drop's /ut
 * folder, for the connected system to download (ADR-032, "SFTP"). Made by a
 * person who may both manage Connect and export SIE (ExportSie checks the
 * latter). The file is written next to its final name and then renamed, so
 * a download never sees half a file, and it is removed after a week
 * (connect:prune), since files in the drop are not encrypted.
 */
final class PutSftpExport
{
    public function __construct(private readonly ExportSie $export) {}

    /**
     * @return string the file name in /ut
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company): string
    {
        if (! $user->hasTeamPermission($company, 'connect:manage')) {
            throw new AuthorizationException;
        }

        $account = CurrentCompany::run($company, fn (): ?ConnectSftpAccount => ConnectSftpAccount::query()->with('responsible')->first());

        if (! $account instanceof ConnectSftpAccount || SftpAccess::refusal($account, $company, checkIp: false) !== null) {
            throw ValidationException::withMessages(['export' => __('SFTP behöver vara påslaget för att lägga ut en fil.')]);
        }

        $fiscalYear = CurrentCompany::run($company, function (): ?FiscalYear {
            $today = CarbonImmutable::today()->toDateString();

            return FiscalYear::query()->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first()
                ?? FiscalYear::query()->orderByDesc('start_date')->first();
        });

        if (! $fiscalYear instanceof FiscalYear) {
            throw ValidationException::withMessages(['export' => __('Företaget har inget räkenskapsår att exportera.')]);
        }

        if (! SftpConfig::ensureHome($account->username)) {
            throw new RuntimeException('The SFTP outbox could not be created; check NONOX_SFTP_ROOT and its permissions.');
        }

        $name = ExportSie::filename($company, $fiscalYear);
        $final = SftpConfig::outbox($account->username).'/'.$name;
        $partial = $final.'.'.bin2hex(random_bytes(6)).'.part';
        $stream = fopen($partial, 'xb') ?: throw new RuntimeException('Could not write to the SFTP outbox.');

        try {
            ($this->export)($user, $fiscalYear, $stream);
        } catch (Throwable $exception) {
            fclose($stream);
            @unlink($partial);

            throw $exception;
        }

        fclose($stream);
        chmod($partial, 0660);
        rename($partial, $final);

        CurrentCompany::run($company, function () use ($user, $company, $account, $fiscalYear, $name): void {
            $account->forceFill(['last_export_at' => now()])->save();

            Audit::record('connect.sftp.export_published', $account, [
                'file' => $name,
                'fiscal_year' => $fiscalYear->label(),
                'removed_after_days' => SftpConfig::exportDays(),
            ], $company, $user);
        });

        return $name;
    }
}
