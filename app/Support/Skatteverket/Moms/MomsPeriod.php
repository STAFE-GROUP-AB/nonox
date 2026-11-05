<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Moms;

use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Skatteverket\Redovisare;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketAuth;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * One VAT period of a company as Skatteverket's momsdeklaration API sees it:
 * the company's twelve-digit identifier, the period's last month, and the
 * return NONOX calculates, with the person's access token. Refuses with a
 * Swedish message whatever stops the call before it is made.
 */
final readonly class MomsPeriod
{
    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     */
    private function __construct(
        public array $period,
        public VatReturn $return,
        public string $redovisare,
        public string $code,
        public MomsApi $api,
    ) {}

    public static function resolve(User $user, Company $company, FiscalYear $fiscalYear, CarbonImmutable $start): self
    {
        if (! Skatteverket::isConfigured()) {
            throw ValidationException::withMessages(['skatteverket' => __('Kopplingen till Skatteverket är inte konfigurerad i den här installationen.')]);
        }

        $period = collect(VatPeriods::of($fiscalYear, $company->vat_period))->first(fn (array $period): bool => $period['start']->isSameDay($start))
            ?? throw ValidationException::withMessages(['skatteverket' => __('Perioden finns inte för företagets momsredovisning.')]);

        $redovisare = Redovisare::of((string) $company->org_number)
            ?? throw ValidationException::withMessages(['skatteverket' => __('Företagets organisationsnummer är inte giltigt. Rätta det under företagets uppgifter.')]);

        $token = SkatteverketAuth::accessToken($user)
            ?? throw ValidationException::withMessages(['skatteverket' => __('Logga in hos Skatteverket med e-legitimation först.')]);

        return new self($period, VatReturn::calculate($fiscalYear, $period['start'], $period['end']), $redovisare, $period['end']->format('Ym'), new MomsApi($token));
    }

    /** @return array<string, int> */
    public function payload(): array
    {
        return MomsPayload::from($this->return->boxes());
    }
}
