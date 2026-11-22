{{--
    The approval of manual prices under the company's lowest contribution margin
    (ADR-082 §9). Shown only while a price needs it. No cost or margin figure
    here: the card names the lines and the floor, so a person without
    prices:view-cost learns nothing about cost.
--}}
<div data-floor-card>
    @if($state->needed)
        <x-ui.card :title="__('Priser under lägsta täckningsgrad')">
            <p class="text-sm text-gray-800" data-floor-lines>
                {{ __('Priset på rad :lines ligger under företagets lägsta täckningsgrad (:floor). Det måste godkännas av någon med behörighet innan :what kan skickas eller skapas.', ['lines' => implode(', ', $state->lines), 'floor' => $state->floor->label(), 'what' => $what]) }}
            </p>

            @if($state->approved())
                <p class="mt-3 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-900" role="status" data-floor-approved>
                    {{ __('Godkänt av :name :date. Ändrar du en rad måste priserna godkännas igen.', ['name' => $decider?->name, 'date' => $state->approval->decided_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i')]) }}
                </p>
            @elseif($state->pending())
                <p class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status" data-floor-pending>
                    {{ __('Väntar på godkännande. Begärt av :name :date.', ['name' => $requester?->name, 'date' => $state->approval->created_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i')]) }}
                </p>

                @if($refusal === null)
                    <div class="mt-3 space-y-3">
                        <x-ui.field for="floor_reason" :label="__('Skäl (krävs om du avslår)')" error="reason">
                            <input id="floor_reason" type="text" class="mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs" wire:model="reason" maxlength="500" autocomplete="off">
                        </x-ui.field>
                        <x-input-error for="approval" />
                        <div class="flex flex-wrap items-center gap-3">
                            <x-button type="button" wire:click="approve({{ $state->approval->id }})" wire:loading.attr="disabled" data-floor-approve>{{ __('Godkänn priserna') }}</x-button>
                            <button type="button" class="text-sm font-medium text-accent-700 underline" wire:click="reject({{ $state->approval->id }})" wire:loading.attr="disabled">{{ __('Avslå') }}</button>
                        </div>
                    </div>
                @elseif($refusal === App\Domain\Pricing\PriceFloorEligibility::WROTE_IT)
                    <p class="mt-3 text-sm text-gray-700" data-floor-wrote-it>{{ __('Du kan inte godkänna priser på ett underlag som du har skrivit, ändrat eller begärt godkännande för. Be någon annan med behörighet.') }}</p>
                @else
                    <p class="mt-3 text-sm text-gray-700">{{ __('Det kräver behörigheten att godkänna priser under lägsta täckningsgrad.') }}</p>
                @endif
            @else
                @if($state->rejected())
                    <p class="mt-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-900" role="status" data-floor-rejected>
                        {{ __('Avslagen av :name: :reason', ['name' => $decider?->name, 'reason' => $state->approval->reason]) }}
                    </p>
                @endif

                @if($canRequest)
                    <div class="mt-3">
                        <x-button type="button" wire:click="askForApproval" wire:loading.attr="disabled" data-floor-request>{{ __('Be om godkännande') }}</x-button>
                    </div>
                @endif
            @endif
        </x-ui.card>
    @endif
</div>
