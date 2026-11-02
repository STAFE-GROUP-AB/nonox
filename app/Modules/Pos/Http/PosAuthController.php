<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Pos\Actions\ConnectPos;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Jobs\SyncProductsJob;
use App\Modules\Pos\Support\PosApiFailed;
use App\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The till's answer after the user approved (or declined) the connection
 * (ADR-042). The flow is pulled from the session first, so a state works
 * once; it must belong to this user, company and till and be fresh. The
 * code is exchanged at once (Onslip's lives 60 seconds) and never logged.
 */
class PosAuthController
{
    public const string SESSION = 'pos.oauth';

    public function __invoke(Request $request, string $provider, ConnectPos $connect): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();
        $till = Provider::from($provider);

        $flow = (array) $request->session()->pull(self::SESSION, []);

        if (! ConnectPos::belongs($flow, (string) $request->query('state', ''), $till, $user, $company)) {
            Toast::flash(__('Kopplingen till kassan gick inte att slutföra. Börja om från Kassa.'), 'error');

            return redirect()->route('pos.index');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            Toast::flash(__(':till gav inte tillgång. Ingenting har kopplats.', ['till' => $till->label()]), 'error');

            return redirect()->route('pos.index');
        }

        try {
            $connection = $connect->complete($user, $company, $till, $flow, (string) $request->query('code'), route('pos.callback', ['provider' => $till->value]));
        } catch (ValidationException $exception) {
            Toast::flash((string) collect($exception->errors())->flatten()->first(), 'error');

            return redirect()->route('pos.index');
        } catch (AuthorizationException) {
            abort(403);
        } catch (PosApiFailed $exception) {
            report($exception);
            Toast::flash(__(':till svarade inte som väntat. Försök igen om en stund.', ['till' => $till->label()]), 'error');

            return redirect()->route('pos.index');
        }

        SyncProductsJob::dispatch($company->id, $connection->id);
        Toast::flash(__(':till är kopplad. Försäljningen kommer in och artiklarna skickas till kassan.', ['till' => $till->label()]));

        return redirect()->route('pos.index');
    }
}
