<?php

declare(strict_types=1);

namespace App\Modules\Portal\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Modules\Portal\Models\PortalAttachment;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\Support\PortalDownloads;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A file attached in a portal thread, for the company's own users with
 * customer-portal:view (ADR-075 §6): looked up inside the company, so
 * another company's thread or file answers 404; streamed as a download,
 * never shown inline.
 */
final class CompanyAttachmentController
{
    public function __invoke(Request $request, int $thread, int $attachment): Response
    {
        $user = $request->user('web');
        $company = $user instanceof User ? $user->currentCompany() : null;
        abort_unless($user instanceof User && $company instanceof Company && $user->hasTeamPermission($company, 'customer-portal:view'), 403);

        $model = PortalThread::query()->findOrFail($thread);
        $file = PortalAttachment::query()->whereKey($attachment)->whereIn('message_id', $model->messages()->select('id'))->firstOrFail();

        return PortalDownloads::file($file);
    }
}
