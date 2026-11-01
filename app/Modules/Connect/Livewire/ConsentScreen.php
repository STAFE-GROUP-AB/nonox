<?php

declare(strict_types=1);

namespace App\Modules\Connect\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Actions\ApproveConnection;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Models\ConnectApp;
use App\Modules\Connect\OAuth\GrantContext;
use App\Modules\Connect\Support\ConnectScopes;
use App\Modules\Connect\Support\ConnectSettings;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Modules\Connect\Support\PendingAuthorization;
use App\Modules\ModuleManager;
use App\Support\Auth\PasskeySession;
use App\Support\Tenancy\SpansCompanies;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User as BridgeUser;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The consent screen (ADR-032): which app, what it may do, in which company.
 * The person picks one of the companies where they are owner or
 * administrator and Connect is on, and approves with a passkey. Approving
 * creates the grant and sends the app back with a one-time code; denying
 * sends it back with access_denied.
 */
class ConsentScreen extends Component implements SpansCompanies
{
    public const string NAME = 'connect.consent-screen';

    #[Locked]
    public string $authToken = '';

    public ?int $companyId = null;

    public function mount(string $authToken): void
    {
        $this->authToken = $authToken;
        $companies = $this->eligibleCompanies();

        if (count($companies) === 1) {
            $this->companyId = $companies[0]->id;
        }
    }

    /** @return array<array-key, mixed> */
    public function passkeyOptions(): array
    {
        return PasskeyStepUp::start($this->user());
    }

    /**
     * @param  ?array<array-key, mixed>  $credential  the passkey's answer; null only where passkeys are skipped locally
     */
    public function approve(?array $credential, ApproveConnection $approve, AuthorizationServer $server): void
    {
        $authRequest = $this->authRequest();
        $user = $this->user();
        $company = collect($this->eligibleCompanies())->firstWhere('id', $this->companyId);

        if (! $company instanceof Company) {
            throw ValidationException::withMessages(['companyId' => __('Välj vilket företag appen ska få ansluta till.')]);
        }

        if ($credential !== null) {
            PasskeyStepUp::confirm($user, $credential);
        } elseif (! PasskeySession::skippedLocally()) {
            throw PasskeyStepUp::missingPasskey();
        }

        $client = ConnectApp::query()->findOrFail($authRequest->getClient()->getIdentifier());
        $requested = ConnectScopes::filter(array_map(fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $authRequest->getScopes()));

        $location = DB::transaction(function () use ($approve, $server, $authRequest, $user, $company, $client, $requested): string {
            $grant = $approve($user, $company, $client, $requested);
            app(GrantContext::class)->grantId = $grant->id;

            $authRequest->setUser(new BridgeUser((string) $user->id));
            $authRequest->setScopes(array_map(fn (string $scope): Scope => new Scope($scope === '' ? 'none' : $scope), $grant->scopes));
            $authRequest->setAuthorizationApproved(true);

            return $server->completeAuthorizationRequest($authRequest, new PsrResponse)->getHeaderLine('Location');
        });

        PendingAuthorization::forget($this->authToken);

        $this->redirect($location);
    }

    public function deny(AuthorizationServer $server): void
    {
        $authRequest = $this->authRequest();
        $authRequest->setUser(new BridgeUser((string) $this->user()->id));
        $authRequest->setAuthorizationApproved(false);

        try {
            $location = $server->completeAuthorizationRequest($authRequest, new PsrResponse)->getHeaderLine('Location');
        } catch (OAuthServerException $exception) {
            $location = $exception->generateHttpResponse(new PsrResponse)->getHeaderLine('Location');
        }

        PendingAuthorization::forget($this->authToken);

        $this->redirect($location);
    }

    public function render(): View
    {
        $authRequest = PendingAuthorization::get($this->authToken);
        $user = $this->user();

        if ($authRequest === null) {
            return view('connect::livewire.consent-expired');
        }

        $client = ConnectApp::query()->find($authRequest->getClient()->getIdentifier());
        $requested = ConnectScopes::filter(array_map(fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $authRequest->getScopes()));
        $company = collect($this->eligibleCompanies())->firstWhere('id', $this->companyId);

        return view('connect::livewire.consent-screen', [
            'client' => $client,
            'scopes' => array_map(fn (string $scope): array => [
                'key' => $scope,
                'label' => ConnectScopes::label($scope),
                'held' => ! $company instanceof Company || $user->hasTeamPermission($company, $scope),
            ], ConnectScopes::filter($requested)),
            'companies' => $this->eligibleCompanies(),
            'hasPasskey' => $user->hasPasskeysEnabled(),
            'skipPasskey' => PasskeySession::skippedLocally(),
            'redirectHost' => parse_url((string) ($authRequest->getRedirectUri() ?? ''), PHP_URL_HOST),
        ]);
    }

    /**
     * Companies the person may connect the app to: owner or administrator,
     * Connect on, not archived, and the app allowed by the company.
     *
     * @return list<Company>
     */
    private function eligibleCompanies(): array
    {
        $user = $this->user();
        $authRequest = PendingAuthorization::get($this->authToken);
        $modules = app(ModuleManager::class);

        if ($authRequest === null) {
            return [];
        }

        $clientId = $authRequest->getClient()->getIdentifier();

        return array_values($user->allTeams()->filter(fn (Company $company): bool => ! $company->isArchived()
            && $modules->isEnabled(ConnectModule::KEY, $company)
            && $user->hasTeamPermission($company, 'connect:manage')
            && ConnectSettings::allowsClient($company, $clientId))->sortBy('name')->values()->all());
    }

    private function authRequest(): AuthorizationRequestInterface
    {
        return PendingAuthorization::get($this->authToken)
            ?? throw ValidationException::withMessages(['companyId' => __('Tiden för att godkänna gick ut. Börja om från appen.')]);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
