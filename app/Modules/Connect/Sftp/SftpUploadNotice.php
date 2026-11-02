<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp;

use App\Models\Company;
use App\Modules\Connect\Models\ConnectSftpUpload;
use Illuminate\Notifications\Notification;

/**
 * An in-app notice that a SIE file arrived over SFTP (ADR-032, "SFTP"):
 * ready to be read through and confirmed under Importera SIE, or rejected
 * with the reason. Stored in the database only, like other notices; the
 * sentence is in "title", which the list of notices shows.
 */
class SftpUploadNotice extends Notification
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly string $uploadId,
        public readonly string $title,
    ) {}

    public static function about(ConnectSftpUpload $upload, Company $company): self
    {
        $replace = ['file' => $upload->file_name, 'company' => (string) $company->name];

        $title = $upload->status === ConnectSftpUpload::ANALYSED
            ? __('SIE-filen :file kom in via SFTP till :company. Läs rapporten och bekräfta under Importera SIE.', $replace)
            : __('SIE-filen :file som kom in via SFTP till :company kunde inte tas emot: :reason', [...$replace, 'reason' => (string) $upload->reason]);

        return new self($company->id, (string) $company->name, $upload->id, $title);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'sftp_upload',
            'company_id' => $this->companyId,
            'company_name' => $this->companyName,
            'upload_id' => $this->uploadId,
            'title' => $this->title,
        ];
    }
}
