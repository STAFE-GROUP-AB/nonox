<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\MergeFields;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\MailingRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The preview of a mailing on its page (ADR-048): the saved text rendered
 * as a recipient would see it, with the viewer's own name in the fields
 * and links not tracked. Framed only by NONOX itself, sandboxed, and it
 * loads nothing. crm:mailings; another company's mailing is 404.
 */
class MailingPreviewController
{
    public function __invoke(Request $request, int $mailing): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();
        abort_unless($user->hasTeamPermission($company, 'crm:mailings'), 403);

        $model = Mailing::query()->findOrFail($mailing);
        $values = MergeFields::values($company, $user, new Person(['name' => $user->name, 'email' => $user->email]), null);

        return response(MailingRenderer::html($model, $company, $values, '#'))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox");
    }
}
