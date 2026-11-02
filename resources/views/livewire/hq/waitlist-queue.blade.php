@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6" x-data="{
    busy: false,
    error: null,
    async withPasskey(call) {
        this.error = null;
        this.busy = true;
        try {
            if (@js($fresh)) {
                await call(null);
                return;
            }
            if (! window.NonoxPasskeys || ! window.NonoxPasskeys.supported()) {
                this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(null) : @js(__('Sidan kunde inte ladda stödet för passkeys. Ladda om sidan och försök igen.'));
                return;
            }
            const options = await $wire.passkeyOptions();
            if (! options) { return; }
            await call(await window.NonoxPasskeys.authenticate(options));
        } catch (error) {
            this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(error) : String(error);
        } finally {
            this.busy = false;
        }
    },
}">
    <section class="grid gap-3 sm:grid-cols-3" aria-label="{{ __('Nyckeltal för kön') }}">
        <div class="rounded-3xl bg-ink px-5 py-4 text-white shadow-xs">
            <span class="block text-sm text-stone-300">{{ __('I kö') }}</span>
            <span class="mt-1 block text-2xl font-bold tabular-nums tracking-tight" data-tile="pending">{{ $counts['PENDING'] ?? 0 }}</span>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <span class="block text-sm text-gray-500">{{ __('Godkända denna vecka') }}</span>
            <span class="mt-1 block text-2xl font-bold tabular-nums tracking-tight text-ink" data-tile="week">{{ $approvedThisWeek }}</span>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <span class="block text-sm text-gray-500">{{ __('Totalt') }}</span>
            <span class="mt-1 block text-2xl font-bold tabular-nums tracking-tight text-ink" data-tile="total">{{ $total }}</span>
        </div>
    </section>

    <x-ui.card>
        <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" x-on:submit.prevent role="search" aria-label="{{ __('Filtrera kön') }}">
            <div>
                <label for="hq-status" class="block text-sm font-medium text-gray-700">{{ __('Status') }}</label>
                <select id="hq-status" wire:model.live="status" class="mt-1 {{ $input }}">
                    @foreach($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }} ({{ $counts[$option->value] ?? 0 }})</option>
                    @endforeach
                    <option value="alla">{{ __('Alla') }} ({{ $total }})</option>
                </select>
            </div>
            <div>
                <label for="hq-industry" class="block text-sm font-medium text-gray-700">{{ __('Bransch') }}</label>
                <select id="hq-industry" wire:model.live="industry" class="mt-1 {{ $input }}">
                    <option value="">{{ __('Alla branscher') }}</option>
                    @foreach($industries as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="hq-employees" class="block text-sm font-medium text-gray-700">{{ __('Anställda') }}</label>
                <select id="hq-employees" wire:model.live="employees" class="mt-1 {{ $input }}">
                    <option value="">{{ __('Alla storlekar') }}</option>
                    @foreach($employeeRanges as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="hq-sort" class="block text-sm font-medium text-gray-700">{{ __('Ordning') }}</label>
                <select id="hq-sort" wire:model.live="sort" class="mt-1 {{ $input }}">
                    <option value="nyast">{{ __('Nyast först') }}</option>
                    <option value="aldst">{{ __('Äldst först') }}</option>
                </select>
            </div>
            <div>
                <label for="hq-search" class="block text-sm font-medium text-gray-700">{{ __('Sök') }}</label>
                <input id="hq-search" type="search" wire:model.live.debounce.400ms="search" class="mt-1 {{ $input }}" placeholder="{{ __('Namn, e-post, företag') }}">
            </div>
        </form>
    </x-ui.card>

    <div class="flex flex-wrap items-center gap-3">
        <button type="button" class="inline-flex items-center rounded-xl bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-60"
                x-bind:disabled="busy || {{ count($selected) === 0 ? 'true' : 'false' }}"
                x-on:click="withPasskey((credential) => $wire.approveSelected(credential))">
            <span x-show="! busy">{{ __('Godkänn markerade') }} ({{ count($selected) }})</span>
            <span x-show="busy" x-cloak>{{ __('Väntar på passkeyn…') }}</span>
        </button>
        <button type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export" class="inline-flex items-center rounded-xl bg-white px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Exportera som CSV') }}</button>
        @if(! $hasPasskey && ! $fresh)
            <p class="text-sm text-red-700">{{ __('Du behöver en passkey för att fatta beslut i HQ. Lägg till en under Profil.') }}</p>
        @endif
    </div>
    <p class="text-sm text-red-700" role="alert" x-show="error" x-text="error" x-cloak></p>
    @error('passkey') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    @error('selected') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

    @if($entries->isEmpty())
        <x-ui.card>
            <x-ui.empty-state :title="__('Inga anmälningar här')">{{ __('Ingen anmälan matchar filtret. Byt status eller töm sökningen.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <caption class="sr-only">{{ __('Anmälningar till kön') }}</caption>
                <thead class="bg-stone-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                    <tr>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Markera') }}</span></th>
                        <th scope="col" class="px-4 py-3">{{ __('Anmäld') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Företag') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Namn') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Bransch') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Anställda') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($entries as $entry)
                        <tr wire:key="entry-{{ $entry->id }}">
                            <td class="px-4 py-3">
                                @if($entry->status->isOpen())
                                    <input type="checkbox" value="{{ $entry->id }}" wire:model.live="selected" class="size-5 rounded border-gray-400 text-accent-600 focus:ring-accent-500" aria-label="{{ __('Markera :company', ['company' => $entry->company_name]) }}">
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums text-gray-600">{{ $entry->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3"><a href="{{ route('hq.waitlist.show', $entry->id) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $entry->company_name }}</a></td>
                            <td class="px-4 py-3">{{ $entry->name }}<span class="block text-xs text-gray-500">{{ $entry->email }}</span></td>
                            <td class="px-4 py-3">{{ App\Domain\Waitlist\WaitlistOptions::label('industry', $entry->industry) }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ App\Domain\Waitlist\WaitlistOptions::label('employees', $entry->employees) }}</td>
                            <td class="px-4 py-3"><x-ui.badge :tone="$entry->status === App\Domain\Waitlist\WaitlistStatus::Approved ? 'success' : ($entry->status === App\Domain\Waitlist\WaitlistStatus::Pending ? 'accent' : 'neutral')">{{ $entry->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $entries->links() }}
    @endif
</div>
