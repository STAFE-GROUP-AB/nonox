<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\Actions\ConnectBank;
use App\Modules\Banking\Providers\FakeBankDataProvider;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Banking\Support\ProviderFailed;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The answer from the bank after the user approved (or declined) the
 * consent. The flow is pulled from the session before anything else, so a
 * state works once; it must belong to this user and company and be fresh
 * (ConnectBank::belongs). The route is rate limited and behind the module.
 * The code and state are never logged.
 */
class BankAuthController
{
    public const string SESSION = 'banking.oauth';

    public function callback(Request $request, ConnectBank $connect): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();

        $flow = (array) $request->session()->pull(self::SESSION, []);

        if (! ConnectBank::belongs($flow, (string) $request->query('state', ''), $user, $company)) {
            Toast::flash(__('Kopplingen till banken gick inte att slutföra. Börja om från Bank.'), 'error');

            return redirect()->route('banking.index');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            Toast::flash(__('Banken gav inte tillgång. Ingenting har hämtats.'), 'error');

            return redirect()->route('banking.index');
        }

        try {
            /** @var array{state: string, company_id: int, user_id: int, provider: string, institution: string, country: string, psu_type: string, valid_until: string, expires_at: int} $flow */
            $connection = $connect->complete($user, $company, $flow, (string) $request->query('code'));
        } catch (ValidationException $exception) {
            Toast::flash((string) collect($exception->errors())->flatten()->first(), 'error');

            return redirect()->route('banking.index');
        } catch (ProviderFailed $exception) {
            report($exception);
            Toast::flash(__('Banken svarade inte som väntat. Försök igen om en stund.'), 'error');

            return redirect()->route('banking.index');
        }

        Toast::flash(__(':bank är kopplad. Välj vilket konto i bokföringen varje bankkonto hör till, så hämtas transaktionerna.', ['bank' => $connection->institution_name]));

        return redirect()->route('banking.index');
    }

    /**
     * The fake bank's approval page, for local demos and tests only. It
     * answers 404 unless the installation uses the fake driver.
     */
    public function fakeBank(Request $request): View
    {
        abort_unless(BankDataProviders::driver() === FakeBankDataProvider::KEY && ! app()->isProduction(), 404);

        $validUntil = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('giltig')) === 1 ? (string) $request->query('giltig') : now()->addDays(90)->toDateString();
        $state = (string) $request->query('state');

        return view('banking::fake-bank', [
            'bank' => (string) $request->query('bank', 'Demobanken'),
            'approve' => route('banking.callback', ['code' => 'fake-'.$validUntil.'-'.Str::random(12), 'state' => $state]),
            'decline' => route('banking.callback', ['error' => 'access_denied', 'state' => $state]),
            'validUntil' => $validUntil,
        ]);
    }
}
