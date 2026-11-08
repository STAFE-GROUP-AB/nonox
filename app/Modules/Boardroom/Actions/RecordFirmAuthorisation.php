<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\BoardroomModule;
use App\Modules\Boardroom\Models\Register;
use App\Modules\Boardroom\Support\BoardroomAccess;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

/**
 * Records that the board has authorised the company's accounting firm to
 * keep the share register (ABL 5 kap. 9 §, owner decision 2): the date of
 * the board's decision and where it is written down. Only the owner or an
 * administrator records it, and it can be withdrawn. The responsibility for
 * the register stays with the board (5 kap. 7 §).
 * TODO(legal-verify): L9 – whether a written board decision is required.
 */
final class RecordFirmAuthorisation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input): Register
    {
        $this->authorize($user, $company);

        $data = Validator::make($input, [
            'decided_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['required', 'string', 'min:3', 'max:255'],
        ], [], [
            'decided_on' => __('Styrelsens beslut'),
            'reference' => __('Var beslutet finns'),
        ])->validate();

        return CurrentCompany::run($company, function () use ($user, $company, $data): Register {
            $register = Register::current();
            $register->forceFill([
                'firm_authorised_on' => CarbonImmutable::parse((string) $data['decided_on'])->toDateString(),
                'firm_authorisation_reference' => trim((string) $data['reference']),
                'firm_authorised_by' => $user->id,
            ])->save();

            Audit::record('share_register.firm_authorised', $register, ['decided_on' => $register->firm_authorised_on?->toDateString()], $company, $user);

            return $register;
        });
    }

    public function withdraw(User $user, Company $company): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, function () use ($user, $company): void {
            $register = Register::current();
            $register->forceFill(['firm_authorised_on' => null, 'firm_authorisation_reference' => null, 'firm_authorised_by' => null])->save();

            Audit::record('share_register.firm_authorisation_withdrawn', $register, [], $company, $user);
        });
    }

    private function authorize(User $user, Company $company): void
    {
        app(ModuleManager::class)->ensureEnabled(BoardroomModule::KEY, $company);

        if (! BoardroomAccess::mayRecordAuthorisation($user, $company)) {
            throw new AuthorizationException;
        }
    }
}
