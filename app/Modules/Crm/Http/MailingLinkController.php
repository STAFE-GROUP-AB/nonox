<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Models\Company;
use App\Modules\Crm\Actions\MailingResponses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The links in a mailing (ADR-048), outside the web middleware: no
 * session and no cookie is set, so nothing is stored on the recipient's
 * device (LEK 9 kap. 28 §), and no CSRF token is needed.
 *
 * - GET /utskick/avregistrera/{token} shows a page with one button. A GET
 *   never unsubscribes: mail scanners open links before the person does.
 * - POST to the same address unsubscribes: the button, and the mail
 *   client's one-click unsubscribe (RFC 8058, List-Unsubscribe-Post).
 * - GET /utskick/lank/{token}/{link} counts the click and redirects to the
 *   URL stored for that link in that recipient's mailing.
 *
 * An unknown or pruned token answers 404 with a plain page.
 */
class MailingLinkController
{
    public function unsubscribePage(string $token): Response
    {
        $recipient = MailingResponses::recipient($token);

        return $this->page($recipient === null ? null : Company::query()->find($recipient->company_id), $recipient === null ? 'gone' : ($recipient->unsubscribed_at !== null ? 'done' : 'ask'), $token, $recipient === null ? 404 : 200);
    }

    public function unsubscribe(Request $request, string $token, MailingResponses $responses): Response
    {
        $recipient = MailingResponses::recipient($token);

        if ($recipient === null) {
            return $this->page(null, 'gone', $token, 404);
        }

        $responses->unsubscribe($recipient, $request->input('List-Unsubscribe') === 'One-Click' ? 'one_click' : 'link');

        return $this->page(Company::query()->find($recipient->company_id), 'done', $token);
    }

    public function click(Request $request, string $token, int $link, MailingResponses $responses): RedirectResponse|Response
    {
        $recipient = MailingResponses::recipient($token);
        $url = $recipient === null ? null : $responses->click($recipient, $link, (string) $request->query('s', ''), $token);

        if ($url === null) {
            return $this->page(null, 'gone', $token, 404);
        }

        return redirect()->away($url, 302)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    private function page(?Company $company, string $state, string $token, int $status = 200): Response
    {
        return response()->view('crm::public.unsubscribe', ['company' => $company, 'state' => $state, 'token' => $token], $status)
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store');
    }
}
