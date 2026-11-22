<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceListKind;
use App\Models\Company;
use App\Models\PriceList;
use App\Models\User;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Support\PosGate;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A till connection's own settings (ADR-042): which way articles go, whether
 * articles and stock are sent to the till, the stock location sales are
 * taken from, the CHANNEL price list the till's prices come from (ADR-082), and (Onslip) which product group each VAT rate goes to.
 */
class SavePosConnectionSettings
{
    public const array VAT_RATES = [2500, 1200, 600, 0];

    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, PosConnection $connection, array $input): PosConnection
    {
        PosGate::manage($user, $company);

        $validated = Validator::make($input, [
            'sync_direction' => ['required', Rule::in([PosConnection::ONE_WAY, PosConnection::TWO_WAY])],
            'push_products' => ['required', 'boolean'],
            'push_stock' => ['required', 'boolean'],
            'location_id' => ['nullable', 'integer'],
            'price_list_id' => ['nullable', 'integer'],
            'product_groups' => ['nullable', 'array'],
            'product_groups.*' => ['nullable', 'integer', 'min:1'],
        ], [], [
            'sync_direction' => __('riktning för artiklar'),
            'location_id' => __('lagerställe'),
            'price_list_id' => __('prislista'),
        ])->validate();

        return CurrentCompany::run($company, function () use ($user, $company, $connection, $validated): PosConnection {
            $locationId = isset($validated['location_id']) ? (int) $validated['location_id'] : null;

            if ($locationId !== null && ! StockLocation::query()->whereKey($locationId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['location_id' => __('Välj ett aktivt lagerställe.')]);
            }

            $priceListId = isset($validated['price_list_id']) ? (int) $validated['price_list_id'] : null;

            if ($priceListId !== null && ! PriceList::query()->whereKey($priceListId)->where('kind', PriceListKind::Channel->value)->where('channel', PriceChannel::Pos->value)->where('currency', 'SEK')->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['price_list_id' => __('Välj en kanalprislista för kassan i kronor.')]);
            }

            $groups = [];

            foreach (self::VAT_RATES as $rate) {
                $group = $validated['product_groups'][(string) $rate] ?? $validated['product_groups'][$rate] ?? null;

                if ($group !== null && $group !== '') {
                    $groups[(string) $rate] = (int) $group;
                }
            }

            $before = $connection->settings;
            $connection->forceFill([
                'location_id' => $locationId,
                'settings' => [
                    ...$connection->settings,
                    'sync_direction' => $validated['sync_direction'],
                    'push_products' => (bool) $validated['push_products'],
                    'push_stock' => $connection->provider->supportsStock() && (bool) $validated['push_stock'],
                    'product_groups' => $groups,
                    'price_list_id' => $priceListId,
                ],
            ])->save();

            Audit::record('pos.settings_updated', $connection, Audit::changes($before, $connection->settings), $company, $user);
            $connection->log('info', __('Inställningarna ändrades av :name.', ['name' => $user->name]));

            return $connection;
        });
    }
}
