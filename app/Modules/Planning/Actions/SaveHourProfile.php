<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Actions\Concerns\ReadsDemandInput;
use App\Modules\Planning\Models\HourProfile;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The company's own estimate of a weekday's sales per hour ("Egen
 * uppskattning", ADR-037 gap 1): 24 percentages that add up to 100, for an
 * area or the whole company. All zero removes the profile, and demand for
 * that weekday is then per day only (unless a till gives the shape).
 */
final class SaveHourProfile
{
    use GuardsPlanning;
    use ReadsDemandInput;

    /**
     * @param  array<int|string, mixed>  $percents  hour => percent as typed ("12,5")
     */
    public function __invoke(User $user, Company $company, ?int $areaId, int $weekday, array $percents): ?HourProfile
    {
        $this->guard($user, $company);

        Validator::make(['area_id' => $areaId, 'weekday' => $weekday], [
            'area_id' => $this->areaRule($company),
            'weekday' => ['required', 'integer', 'between:1,7'],
        ])->validate();

        $shares = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $typed = trim(str_replace(',', '.', (string) ($percents[$hour] ?? '')));

            if ($typed === '') {
                $shares[] = 0;

                continue;
            }

            if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $typed) !== 1) {
                throw ValidationException::withMessages(["percents.{$hour}" => __('Skriv andelen kl. :hour som procent, till exempel 12,5.', ['hour' => sprintf('%02d', $hour)])]);
            }

            [$whole, $fraction] = array_pad(explode('.', $typed), 2, '');
            $shares[] = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        }

        $total = array_sum($shares);

        if ($total !== 0 && $total !== HourProfile::WHOLE) {
            throw ValidationException::withMessages(['percents' => __('Andelarna ska bli 100 % tillsammans. Nu blir de :sum %.', ['sum' => str_replace('.', ',', rtrim(rtrim(number_format($total / 100, 2, '.', ''), '0'), '.'))])]);
        }

        return CurrentCompany::run($company, fn (): ?HourProfile => DB::transaction(function () use ($user, $company, $areaId, $weekday, $shares, $total): ?HourProfile {
            $profile = HourProfile::query()
                ->when($areaId === null, fn ($query) => $query->whereNull('area_id'), fn ($query) => $query->where('area_id', $areaId))
                ->where('weekday', $weekday)
                ->lockForUpdate()
                ->first();

            if ($total === 0) {
                if ($profile !== null) {
                    Audit::record('planning.hour_profile_deleted', $profile, ['area_id' => $areaId, 'weekday' => $weekday], $company, $user);
                    $profile->delete();
                }

                return null;
            }

            $created = $profile === null;
            $profile ??= new HourProfile(['area_id' => $areaId, 'weekday' => $weekday]);
            $profile->fill(['shares' => $shares, 'updated_by' => $user->id])->save();
            Audit::record($created ? 'planning.hour_profile_created' : 'planning.hour_profile_updated', $profile, ['area_id' => $areaId, 'weekday' => $weekday], $company, $user);

            return $profile;
        }));
    }
}
