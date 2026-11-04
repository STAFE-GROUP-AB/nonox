@php($input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div>
    @if($templates->isNotEmpty())
        <form wire:submit="create" @class(["flex flex-wrap items-end gap-3", "rounded-3xl bg-white p-6 shadow-xs ring-1 ring-stone-200" => $boxed]) data-testid="crm-quote-from-template">
            <div class="min-w-[12rem] flex-1">
                <label for="qft-template-{{ $this->getId() }}" class="block text-sm font-medium text-gray-700">{{ __('Ny offert från mall') }}</label>
                <select id="qft-template-{{ $this->getId() }}" class="{{ $input }} mt-1" wire:model="templateId">
                    <option value="">{{ __('Välj mall…') }}</option>
                    @foreach($templates as $template)
                        <option value="{{ $template->id }}">{{ $template->name }}</option>
                    @endforeach
                </select>
                <x-input-error for="templateId" class="mt-1" />
            </div>
            @if($fixedCustomerId === null)
                <div class="min-w-[12rem] flex-1">
                    <label for="qft-customer-{{ $this->getId() }}" class="block text-sm font-medium text-gray-700">{{ __('Kund') }}</label>
                    <select id="qft-customer-{{ $this->getId() }}" class="{{ $input }} mt-1" wire:model="customerId">
                        <option value="">{{ __('Välj kund…') }}</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->number }} {{ $customer->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error for="customerId" class="mt-1" />
                </div>
            @endif
            <x-button wire:loading.attr="disabled">{{ __('Skapa offert') }}</x-button>
            <x-input-error for="template" class="basis-full" />
        </form>
    @endif
</div>
