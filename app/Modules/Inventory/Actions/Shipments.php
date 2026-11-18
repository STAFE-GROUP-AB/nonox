<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\NumberSeries;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\ShipmentPackage;
use App\Modules\Inventory\Models\ShipmentPackageLine;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\ShipmentContents;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Packing and shipments (ADR-081 E14): a shipment is made from a completed
 * pick list, the goods are put in packages with weight and dimensions, and
 * the shipment is sent with a carrier and a tracking number written by hand.
 * Stock is not touched: the goods left stock when the pick list was
 * completed. A shipped shipment is final, as the goods have left.
 */
class Shipments
{
    use GuardsInventory;

    public const string SERIES = 'shipments';

    public const int MAX_PACKAGE_GRAMS = 1_000_000_000;

    public const int MAX_PACKAGE_MM = 100_000;

    public function fromPickList(User $user, Company $company, PickList $list): Shipment
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): Shipment => DB::transaction(function () use ($user, $company, $list): Shipment {
            $list = PickList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();

            if ($list->status !== PickList::COMPLETED) {
                throw ValidationException::withMessages(['pick_list' => __('Bara en plockad plocklista kan packas. Klarmarkera plocklistan först.')]);
            }

            $existing = Shipment::query()->where('pick_list_id', $list->id)->where('status', '!=', Shipment::CANCELLED)->first();

            if ($existing instanceof Shipment) {
                return $existing;
            }

            if (ShipmentContents::picked($list) === []) {
                throw ValidationException::withMessages(['pick_list' => __('Inget plockades på plocklistan, så det finns inget att packa.')]);
            }

            $orderId = $list->source_type === (new SalesDocument)->getMorphClass() && $list->source_id !== null && SalesDocument::query()->whereKey($list->source_id)->exists()
                ? $list->source_id
                : null;

            $shipment = Shipment::create([
                'number' => NumberSeries::take(self::SERIES),
                'pick_list_id' => $list->id,
                'customer_id' => $list->customer_id,
                'sales_document_id' => $orderId,
                'status' => Shipment::DRAFT,
                'created_by' => $user->id,
            ]);

            Audit::record('inventory.shipment_created', $shipment, ['number' => $shipment->number, 'pick_list_id' => $list->id], $company, $user);

            return $shipment;
        }));
    }

    /**
     * A new, empty package; weight and dimensions may be filled in now or later.
     *
     * @param  array<string, mixed>  $input
     */
    public function addPackage(User $user, Company $company, Shipment $shipment, array $input = []): ShipmentPackage
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): ShipmentPackage => DB::transaction(function () use ($user, $company, $shipment, $input): ShipmentPackage {
            $shipment = $this->draft($shipment);
            $position = (int) ShipmentPackage::query()->where('shipment_id', $shipment->id)->max('position') + 1;
            $package = ShipmentPackage::create(['shipment_id' => $shipment->id, 'position' => $position, ...$this->measures($input)]);

            Audit::record('inventory.shipment_package_added', $shipment, ['number' => $shipment->number, 'package' => $package->id], $company, $user);

            return $package;
        }));
    }

    /**
     * Weight in kilograms and length, width and height in centimetres, as
     * people write them; kept as grams and millimetres.
     *
     * @param  array{weight?: mixed, length?: mixed, width?: mixed, height?: mixed}  $input
     */
    public function updatePackage(User $user, Company $company, ShipmentPackage $package, array $input): ShipmentPackage
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, function () use ($package, $input): ShipmentPackage {
            $this->draft(Shipment::query()->findOrFail($package->shipment_id));
            $package->update($this->measures($input));

            return $package;
        });
    }

    public function removePackage(User $user, Company $company, ShipmentPackage $package): void
    {
        $this->guard($user, $company, 'count');

        CurrentCompany::run($company, function () use ($user, $company, $package): void {
            DB::transaction(function () use ($user, $company, $package): void {
                $shipment = $this->draft(Shipment::query()->findOrFail($package->shipment_id));
                $package->lines()->delete();
                $package->delete();
                Audit::record('inventory.shipment_package_removed', $shipment, ['number' => $shipment->number, 'package' => $package->id], $company, $user);
            });
        });
    }

    /**
     * Puts a quantity of an article (and its batch or serial number) in a
     * package. Only what the pick list took, and only what is not already in
     * a package, can be packed.
     */
    public function pack(User $user, Company $company, ShipmentPackage $package, int $articleId, ?int $lotId, string $quantity): ShipmentPackageLine
    {
        $this->guard($user, $company, 'count');

        try {
            $milli = Quantity::parse($quantity);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['quantity' => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($milli <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Antalet måste vara större än noll.')]);
        }

        return CurrentCompany::run($company, fn (): ShipmentPackageLine => DB::transaction(function () use ($user, $company, $package, $articleId, $lotId, $milli): ShipmentPackageLine {
            $shipment = $this->draft(Shipment::query()->findOrFail($package->shipment_id));
            $remaining = ShipmentContents::remaining($shipment);
            $left = $remaining[ShipmentContents::key($articleId, $lotId)]['milli'] ?? 0;

            if ($left === 0) {
                throw ValidationException::withMessages(['quantity' => __('Den varan finns inte kvar att packa från plocklistan.')]);
            }

            if ($milli > $left) {
                throw ValidationException::withMessages(['quantity' => __('Det finns bara :quantity kvar att packa av den varan.', ['quantity' => Quantity::format($left)])]);
            }

            $line = ShipmentPackageLine::query()->where('shipment_package_id', $package->id)->where('article_id', $articleId)
                ->when($lotId === null, fn ($query) => $query->whereNull('lot_id'), fn ($query) => $query->where('lot_id', $lotId))->first();

            if ($line instanceof ShipmentPackageLine) {
                $line->update(['quantity_milli' => $line->quantity_milli + $milli]);
            } else {
                $line = ShipmentPackageLine::create(['shipment_package_id' => $package->id, 'article_id' => $articleId, 'lot_id' => $lotId, 'quantity_milli' => $milli]);
            }

            Audit::record('inventory.shipment_packed', $shipment, ['number' => $shipment->number, 'package' => $package->id, 'article_id' => $articleId, 'lot_id' => $lotId], $company, $user);

            return $line;
        }));
    }

    public function unpack(User $user, Company $company, ShipmentPackageLine $line): void
    {
        $this->guard($user, $company, 'count');

        CurrentCompany::run($company, function () use ($user, $company, $line): void {
            DB::transaction(function () use ($user, $company, $line): void {
                $package = ShipmentPackage::query()->findOrFail($line->shipment_package_id);
                $shipment = $this->draft(Shipment::query()->findOrFail($package->shipment_id));
                $line->delete();
                Audit::record('inventory.shipment_unpacked', $shipment, ['number' => $shipment->number, 'package' => $package->id, 'article_id' => $line->article_id, 'lot_id' => $line->lot_id], $company, $user);
            });
        });
    }

    /** Everything not yet in a package goes in a new package: the common case of one parcel. */
    public function packRemaining(User $user, Company $company, Shipment $shipment): ShipmentPackage
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): ShipmentPackage => DB::transaction(function () use ($user, $company, $shipment): ShipmentPackage {
            $shipment = $this->draft($shipment);
            $remaining = ShipmentContents::remaining($shipment);

            if ($remaining === []) {
                throw ValidationException::withMessages(['shipment' => __('Allt är redan packat.')]);
            }

            $package = $this->addPackage($user, $company, $shipment);

            foreach ($remaining as $item) {
                ShipmentPackageLine::create(['shipment_package_id' => $package->id, 'article_id' => $item['article_id'], 'lot_id' => $item['lot_id'], 'quantity_milli' => $item['milli']]);
            }

            Audit::record('inventory.shipment_packed', $shipment, ['number' => $shipment->number, 'package' => $package->id, 'lines' => count($remaining)], $company, $user);

            return $package;
        }));
    }

    /**
     * Sends the shipment: everything picked must be in a package, and no
     * package may be empty. The carrier and the tracking number are written
     * by hand; no carrier is connected.
     */
    public function ship(User $user, Company $company, Shipment $shipment, string $carrier, string $trackingNumber, CarbonImmutable $date): Shipment
    {
        $this->guard($user, $company, 'count');
        $carrier = trim($carrier);
        $trackingNumber = trim($trackingNumber);

        if (mb_strlen($carrier) > 60) {
            throw ValidationException::withMessages(['carrier' => __('Transportören får vara högst 60 tecken.')]);
        }

        if (mb_strlen($trackingNumber) > 60) {
            throw ValidationException::withMessages(['tracking_number' => __('Spårningsnumret får vara högst 60 tecken.')]);
        }

        return CurrentCompany::run($company, fn (): Shipment => DB::transaction(function () use ($user, $company, $shipment, $carrier, $trackingNumber, $date): Shipment {
            $shipment = $this->draft($shipment);

            $completed = StockMovement::query()->where('source_type', (new PickList)->getMorphClass())->where('source_id', (string) $shipment->pick_list_id)->where('type', MovementType::Pick->value)->max('occurred_on');

            if ($date->isFuture() && ! $date->isToday()) {
                throw ValidationException::withMessages(['date' => __('Datumet får inte ligga i framtiden.')]);
            }

            if ($completed !== null && $date->toDateString() < CarbonImmutable::parse($completed)->toDateString()) {
                throw ValidationException::withMessages(['date' => __('Försändelsen kan inte skickas före den dag plocklistan blev klar.')]);
            }

            $packages = ShipmentPackage::query()->where('shipment_id', $shipment->id)->withCount('lines')->get();

            if ($packages->isEmpty()) {
                throw ValidationException::withMessages(['shipment' => __('Lägg varorna i minst ett kolli först.')]);
            }

            if ($packages->contains(fn (ShipmentPackage $package): bool => $package->lines_count === 0)) {
                throw ValidationException::withMessages(['shipment' => __('Ett kolli är tomt. Lägg varor i det eller ta bort det.')]);
            }

            if (ShipmentContents::remaining($shipment) !== []) {
                throw ValidationException::withMessages(['shipment' => __('Alla plockade varor måste ligga i ett kolli innan försändelsen skickas.')]);
            }

            $customer = $shipment->customer_id === null ? null : Customer::query()->find($shipment->customer_id);

            $shipment->update([
                'status' => Shipment::SHIPPED,
                'carrier' => $carrier === '' ? null : $carrier,
                'tracking_number' => $trackingNumber === '' ? null : $trackingNumber,
                'shipped_at' => $date->setTimeFrom(CarbonImmutable::now()),
                'shipped_by' => $user->id,
                'ship_to' => $customer instanceof Customer ? self::address($customer) : null,
            ]);

            Audit::record('inventory.shipment_shipped', $shipment, ['number' => $shipment->number, 'packages' => $packages->count(), 'order' => $shipment->sales_document_id !== null], $company, $user);

            return $shipment;
        }));
    }

    /** Carrier and tracking number can be added or corrected after the shipment has left. */
    public function setTracking(User $user, Company $company, Shipment $shipment, string $carrier, string $trackingNumber): Shipment
    {
        $this->guard($user, $company, 'count');
        $carrier = trim($carrier);
        $trackingNumber = trim($trackingNumber);

        if (mb_strlen($carrier) > 60 || mb_strlen($trackingNumber) > 60) {
            throw ValidationException::withMessages(['tracking_number' => __('Transportören och spårningsnumret får vara högst 60 tecken.')]);
        }

        return CurrentCompany::run($company, fn (): Shipment => DB::transaction(function () use ($user, $company, $shipment, $carrier, $trackingNumber): Shipment {
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if ($shipment->status !== Shipment::SHIPPED) {
                throw ValidationException::withMessages(['shipment' => __('Transportör och spårningsnummer kan rättas först när försändelsen är skickad.')]);
            }

            $shipment->update(['carrier' => $carrier === '' ? null : $carrier, 'tracking_number' => $trackingNumber === '' ? null : $trackingNumber]);
            Audit::record('inventory.shipment_tracking_set', $shipment, ['number' => $shipment->number], $company, $user);

            return $shipment;
        }));
    }

    /** A shipment not yet sent can be cancelled; the pick list can then be packed anew. */
    public function cancel(User $user, Company $company, Shipment $shipment): Shipment
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): Shipment => DB::transaction(function () use ($user, $company, $shipment): Shipment {
            $shipment = $this->draft($shipment);
            ShipmentPackageLine::query()->whereIn('shipment_package_id', ShipmentPackage::query()->where('shipment_id', $shipment->id)->select('id'))->delete();
            ShipmentPackage::query()->where('shipment_id', $shipment->id)->delete();
            $shipment->update(['status' => Shipment::CANCELLED]);
            Audit::record('inventory.shipment_cancelled', $shipment, ['number' => $shipment->number], $company, $user);

            return $shipment;
        }));
    }

    private function draft(Shipment $shipment): Shipment
    {
        $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

        if ($shipment->status !== Shipment::DRAFT) {
            throw ValidationException::withMessages(['shipment' => __('Försändelsen är redan skickad eller makulerad och kan inte ändras.')]);
        }

        return $shipment;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{weight_grams?: ?int, length_mm?: ?int, width_mm?: ?int, height_mm?: ?int}
     */
    private function measures(array $input): array
    {
        $measures = [];

        foreach (['weight' => ['weight_grams', 1000, self::MAX_PACKAGE_GRAMS], 'length' => ['length_mm', 10, self::MAX_PACKAGE_MM], 'width' => ['width_mm', 10, self::MAX_PACKAGE_MM], 'height' => ['height_mm', 10, self::MAX_PACKAGE_MM]] as $field => [$column, $factor, $max]) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $text = str_replace([' ', "\u{00A0}"], '', trim((string) $input[$field]));
            $text = str_replace(',', '.', $text);

            if ($text === '') {
                $measures[$column] = null;

                continue;
            }

            if (preg_match('/^\d+(\.\d{1,3})?$/', $text) !== 1) {
                throw ValidationException::withMessages([$field => $field === 'weight' ? __('Vikten går inte att läsa. Skriv i kilo, till exempel 2,5.') : __('Måttet går inte att läsa. Skriv i centimeter, till exempel 30 eller 12,5.')]);
            }

            $value = (int) round((float) $text * $factor);

            if ($value > $max) {
                throw ValidationException::withMessages([$field => __('Värdet är för stort.')]);
            }

            $measures[$column] = $value;
        }

        return $measures;
    }

    private static function address(Customer $customer): string
    {
        return collect([$customer->name, $customer->address_line1, $customer->address_line2, trim("{$customer->postal_code} {$customer->city}"), $customer->country !== 'SE' ? $customer->country : null])
            ->filter(fn (?string $line): bool => $line !== null && trim($line) !== '')->implode("\n");
    }
}
