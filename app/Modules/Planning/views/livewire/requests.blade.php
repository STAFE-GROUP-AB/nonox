@use('App\Modules\Planning\Models\ShiftRequest')
@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $locale = app()->getLocale();
    $describe = fn ($shift) => $shift === null ? '' : ucfirst($shift->starts_at->locale($locale)->isoFormat('ddd D MMM')).' '.$shift->starts_at->format('H:i').'–'.$shift->ends_at->format('H:i').($shift->area_id && $areas->get($shift->area_id) ? ' · '.$areas->get($shift->area_id) : '');
    $person = fn ($employee) => $employee ? $employee->first_name.' '.$employee->last_name : __('Tidigare anställd');
    $statusTone = [ShiftRequest::APPROVED => 'success', ShiftRequest::REJECTED => 'accent', ShiftRequest::DECLINED => 'accent', ShiftRequest::WITHDRAWN => 'neutral'];
@endphp
<div class="space-y-6">
    <x-ui.card :title="__('Väntar på svar')">
        <ul class="mt-2 divide-y divide-stone-100">
            @forelse($pending as $item)
                <li class="space-y-3 py-4" wire:key="pending-{{ $item->id }}" data-request="{{ $item->id }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $person($item->requester) }} · {{ ShiftRequest::kindLabel($item->kind) }}</p>
                            <p class="text-sm tabular-nums text-gray-700">
                                {{ $describe($item->shift) }}
                                @if($item->kind === ShiftRequest::SWAP)
                                    ↔ {{ $describe($item->counterpartShift) }} ({{ $person($item->counterpart) }})
                                @endif
                            </p>
                            @if($item->kind === ShiftRequest::SWAP && $item->counterpart_answered_at)
                                <p class="mt-1 text-sm font-medium text-green-800" data-counterpart-accepted>{{ __(':name har sagt ja till bytet (:date).', ['name' => $person($item->counterpart), 'date' => $item->counterpart_answered_at->setTimezone(App\Modules\Planning\Support\LocalTimestamp::ZONE)->format('Y-m-d H:i')]) }}</p>
                            @endif
                            @if($item->note)<p class="mt-1 text-sm text-gray-600">“{{ $item->note }}”</p>@endif
                            @if($item->kind === ShiftRequest::DROP)
                                <p class="mt-1 text-xs text-gray-500">{{ __('Godkänner du blir passet ett öppet pass som andra kan ta.') }}</p>
                            @endif
                        </div>
                        <span class="text-xs text-gray-500">{{ $item->created_at->setTimezone(App\Modules\Planning\Support\LocalTimestamp::ZONE)->format('Y-m-d H:i') }}</span>
                    </div>

                    @if(($warnings[$item->id] ?? []) !== [])
                        <ul class="space-y-1 rounded-2xl bg-amber-50 p-3 text-sm text-amber-900" data-warnings>
                            @foreach($warnings[$item->id] as $warning)
                                <li>{{ $warning['message'] }}@if($warning['source'] !== '') <span class="text-xs text-amber-800">({{ $warning['source'] }})</span>@endif</li>
                            @endforeach
                            <li class="text-xs text-amber-800">{{ __('Varningarna stoppar inte ett godkännande. Reglerna är inte kontrollerade av en jurist ännu.') }}</li>
                        </ul>
                    @elseif($item->kind !== ShiftRequest::DROP)
                        <p class="text-sm text-green-800">{{ __('Inga varningar för vilotid eller arbetstid.') }}</p>
                    @endif

                    @if($canManage)
                        @if($rejecting === $item->id)
                            <form wire:submit="reject" class="space-y-3">
                                <x-ui.field for="reason-{{ $item->id }}" :label="__('Meddelande till personen (frivilligt)')">
                                    <input id="reason-{{ $item->id }}" type="text" maxlength="500" class="{{ $input }} mt-1" wire:model="reason">
                                </x-ui.field>
                                <div class="flex gap-3">
                                    <x-danger-button type="submit">{{ __('Avslå') }}</x-danger-button>
                                    <x-secondary-button type="button" wire:click="$set('rejecting', null)">{{ __('Avbryt') }}</x-secondary-button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-wrap gap-3">
                                <x-button type="button" wire:click="approve({{ $item->id }})" wire:confirm="{{ __('Godkänna förfrågan? Passet ändras och publiceras direkt, och de som berörs får besked.') }}">{{ __('Godkänn') }}</x-button>
                                <x-secondary-button type="button" wire:click="startReject({{ $item->id }})">{{ __('Avslå') }}</x-secondary-button>
                            </div>
                        @endif
                    @endif
                </li>
            @empty
                <li class="py-2"><x-ui.empty-state :title="__('Inga förfrågningar väntar')">{{ __('När personalen ber om ett öppet pass, ett byte eller att få lämna ett pass i Min sida syns det här.') }}</x-ui.empty-state></li>
            @endforelse
        </ul>
    </x-ui.card>

    @if($awaiting->isNotEmpty())
        <x-ui.card :title="__('Byten som väntar på kollegan')" :description="__('Kollegan har inte svarat i Min sida än. Bytet kommer hit för beslut när kollegan har sagt ja.')">
            <ul class="mt-2 divide-y divide-stone-100" data-awaiting>
                @foreach($awaiting as $item)
                    <li class="py-3 text-sm" wire:key="awaiting-{{ $item->id }}">
                        <span class="block font-medium text-ink">{{ $person($item->requester) }} → {{ $person($item->counterpart) }}</span>
                        <span class="block tabular-nums text-gray-600">{{ $describe($item->shift) }} ↔ {{ $describe($item->counterpartShift) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Öppna pass de närmaste fyra veckorna')" :description="__('Publicerade pass utan person. Personalen kan be om dem i Min sida när det är påslaget under Inställningar.')">
        <ul class="mt-2 divide-y divide-stone-100">
            @forelse($open as $shift)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" wire:key="open-{{ $shift->id }}">
                    <span class="tabular-nums text-ink">{{ $describe($shift) }}</span>
                    <span class="text-gray-600">{{ trans_choice('{0} Ingen har frågat|{1} 1 har frågat|[2,*] :count har frågat', $claims[$shift->id] ?? 0, ['count' => $claims[$shift->id] ?? 0]) }}</span>
                </li>
            @empty
                <li class="py-3 text-sm text-gray-600">{{ __('Inga öppna pass är publicerade.') }}</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if($decided->isNotEmpty())
        <x-ui.card :title="__('Besvarade de senaste två veckorna')">
            <ul class="mt-2 divide-y divide-stone-100">
                @foreach($decided as $item)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" wire:key="decided-{{ $item->id }}">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-ink">{{ $person($item->requester) }} · {{ ShiftRequest::kindLabel($item->kind) }}</span>
                            <span class="block tabular-nums text-gray-600">{{ $describe($item->shift) }}@if($item->counterpartShift) ↔ {{ $describe($item->counterpartShift) }}@endif</span>
                            @if($item->decision_note)<span class="block text-gray-600">{{ $item->decision_note }}</span>@endif
                        </span>
                        <x-ui.badge :tone="$statusTone[$item->status] ?? 'neutral'">{{ ShiftRequest::statusLabel($item->status) }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
