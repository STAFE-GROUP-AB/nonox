<?php

declare(strict_types=1);

namespace App\Modules\Signing\Http;

use App\Domain\Signing\Verification\SignerCheck;
use App\Models\Company;
use App\Models\User;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Modules\Signing\Support\BulkSendRows;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Ladda ner exempelfil" (ADR-046 S15): a CSV with the columns a bulk send
 * of the template expects and one line of made-up values, so that the sender
 * starts from the right headers. Semicolon-separated with a byte order mark,
 * which Excel opens as UTF-8. signing:manage, and the template must be one
 * the member may see.
 */
final class BulkSendExampleController
{
    public function __invoke(Request $request, string $template): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();
        abort_unless(Str::isUuid($template) && $user->hasTeamPermission($company, SigningModule::MANAGE), 404);

        $found = SigningTemplate::query()->visibleTo($user, $company)->with('roles')->findOrFail($template);
        abort_unless($found->hasFile(), 404);

        $values = [];

        foreach ($found->roles as $number => $role) {
            $values[] = 'namn'.($number + 1).'@example.test';
            $values[] = 'Exempel '.($number + 1);
            $values[] = $found->language;

            if ($role->verification === SignerCheck::AccessCode->value) {
                $values[] = '';
            }
        }

        $handle = fopen('php://temp', 'r+') ?: throw new \RuntimeException('No temporary stream.');
        fputcsv($handle, BulkSendRows::headersFor($found), ';');
        fputcsv($handle, $values, ';');
        rewind($handle);
        $csv = "\xEF\xBB\xBF".stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="massutskick-exempel.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
