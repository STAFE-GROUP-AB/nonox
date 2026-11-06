<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Agd;

use App\Domain\Company\LegalForm;
use App\Models\Company;
use App\Models\User;
use App\Support\Skatteverket\Redovisare;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketAuth;
use Illuminate\Validation\ValidationException;

/**
 * A company as Skatteverket's arbetsgivardeklaration APIs see it: the
 * employer's twelve-digit identifier ("arbetsgivarregistrerad") and the
 * person's access token for the API's scope. Refuses with a Swedish message
 * whatever stops the call before it is made.
 */
final readonly class AgdEmployer
{
    private function __construct(public string $id, public AgdApi $api) {}

    public static function resolve(User $user, Company $company, string ...$scopes): self
    {
        if (! Skatteverket::isAvailableTo($user)) {
            throw ValidationException::withMessages(['skatteverket' => __('Kopplingen till Skatteverket är inte konfigurerad i den här installationen.')]);
        }

        // The payroll module does not build the declaration of an enskild firma (AgiSubmissions::employerId).
        if ($company->legal_form === LegalForm::Ef) {
            throw ValidationException::withMessages(['skatteverket' => __('Arbetsgivardeklaration för enskild firma görs med ägarens personnummer och stöds inte ännu.')]);
        }

        $id = Redovisare::of((string) $company->org_number)
            ?? throw ValidationException::withMessages(['skatteverket' => __('Företagets organisationsnummer är inte giltigt. Rätta det under företagets uppgifter.')]);

        $token = SkatteverketAuth::accessToken($user, $scopes[0] ?? null);
        $covered = $token !== null && array_diff($scopes, SkatteverketAuth::grantedScopes($user) ?? []) === [];

        if (! $covered) {
            throw ValidationException::withMessages(['skatteverket' => SkatteverketAuth::isSignedIn($user)
                ? __('Din inloggning hos Skatteverket gäller inte arbetsgivardeklarationen. Logga in hos Skatteverket igen.')
                : __('Logga in hos Skatteverket med e-legitimation först.')]);
        }

        return new self($id, new AgdApi((string) $token));
    }

    /** The period as Skatteverket writes it: ÅÅÅÅMM. */
    public static function period(string $period): string
    {
        return str_replace('-', '', $period);
    }
}
