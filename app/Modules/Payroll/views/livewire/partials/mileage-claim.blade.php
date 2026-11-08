{{-- A mileage claim from the körjournal (ADR-072 §4): one line per trip, approved without an account. --}}
@php
    $groups = (array) ($expense->trip_data['groups'] ?? []);
    $notes = (array) ($expense->trip_data['notes'] ?? []);
    $cars = collect((array) ($expense->trip_data['lines'] ?? []))->mapWithKeys(fn ($line) => [(string) ($line['trip_id'] ?? '') => (string) ($line['car'] ?? '')]);
    $carLabels = ['OWN_BENEFIT' => __('förmånsbil'), 'POOL' => __('poolbil'), 'OTHER_BENEFIT' => __('annans förmånsbil')];
@endphp
<div class="flex flex-wrap items-center gap-3">
    <span class="min-w-0 flex-1 basis-40">
        <span class="block font-medium text-gray-900">{{ $expense->employee?->first_name }} {{ $expense->employee?->last_name }} · {{ $expense->description }}</span>
        <span class="block text-sm text-gray-600">{{ $expense->type === 'MILEAGE_OWN_CAR' ? __('Egen bil') : __('Företagets bil') }}
            @if(($expense->trip_data['paid_all_fuel'] ?? null) === true) · {{ __('Föraren har intygat att hen betalat allt drivmedel') }}@endif
        </span>
    </span>
    <span class="text-sm font-semibold tabular-nums text-gray-900">{{ ($expense->trip_data['rate_pending'] ?? false) ? __('Beloppet väntar') : $expense->amount_ore->format() }}</span>
</div>
@if($expense->type === 'MILEAGE_COMPANY_CAR')
    <p class="rounded-lg bg-accent-50 p-3 text-sm text-accent-900">{{ __('Kontrollera drivmedelskortet: har företaget betalat något drivmedel för bilen i månaden, kan resorna bara ersättas som lön.') }}</p>
@endif
@foreach($notes as $note)
    <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ $note }}</p>
@endforeach
<div class="overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                <th class="py-1 pr-3 font-medium">{{ __('Datum') }}</th>
                <th class="py-1 pr-3 font-medium">{{ __('Bil') }}</th>
                <th class="py-1 pr-3 font-medium">{{ __('Typ') }}</th>
                <th class="py-1 pr-3 font-medium">{{ __('Från – till') }}</th>
                <th class="py-1 pr-3 font-medium">{{ __('Ärende') }}</th>
                <th class="py-1 text-right font-medium">{{ __('Sträcka') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($claimTrips as $trip)
                <tr wire:key="claim-trip-{{ $trip->id }}">
                    <td class="py-1.5 pr-3 tabular-nums">{{ $trip->trip_date->toDateString() }}</td>
                    <td class="py-1.5 pr-3">{{ $trip->vehicle->displayName() }}@if(isset($carLabels[$cars[$trip->id] ?? ''])) <span class="text-gray-600">({{ $carLabels[$cars[$trip->id]] }})</span>@endif</td>
                    <td class="py-1.5 pr-3">{{ $trip->kind->label() }}</td>
                    <td class="py-1.5 pr-3">{{ $trip->route() }}</td>
                    <td class="py-1.5 pr-3">{{ $trip->purpose }}@if($trip->corrects_trip_id) <span class="text-accent-800">({{ __('rättelse') }}: {{ $trip->correction_reason }})</span>@endif</td>
                    <td class="py-1.5 text-right tabular-nums">{{ $trip->distanceLabel() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<ul class="text-sm text-gray-600">
    @foreach($groups as $group)
        <li>{{ App\Domain\Mileage\MileagePayCode::label((string) $group['pay_code']) }}: {{ App\Domain\Mileage\Distance::formatMil((int) $group['hm']) }}@if($group['amount_ore'] !== null) · {{ App\Support\Money\Money::ofOre((int) $group['amount_ore'])->format() }}@endif</li>
    @endforeach
</ul>
<div class="flex flex-wrap items-center gap-4">
    <x-button type="button" wire:click="approveMileage({{ $expense->id }})" wire:confirm="{{ __('Godkänna milersättningen? Den betalas med nästa lön och resorna kan sedan inte ändras.') }}" wire:loading.attr="disabled">{{ __('Godkänn milersättningen') }}</x-button>
    <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('rejectingExpense', {{ $expense->id }})">{{ __('Avslå') }}</button>
</div>
