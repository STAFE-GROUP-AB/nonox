@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($onCompanies = $tab === App\Livewire\Hq\Customers::COMPANIES)
<div class="space-y-6">
    <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Kunder') }}">
        @foreach([App\Livewire\Hq\Customers::COMPANIES => __('Företag'), App\Livewire\Hq\Customers::USERS => __('Användare')] as $key => $label)
            <button type="button" role="tab" id="hq-tab-{{ $key }}" aria-controls="hq-panel" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="showTab('{{ $key }}')" @class([
                'rounded-xl px-4 py-2 text-sm font-semibold focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500',
                'bg-ink text-white' => $tab === $key,
                'bg-white text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    <div id="hq-panel" role="tabpanel" aria-labelledby="hq-tab-{{ $tab }}" class="space-y-6">
        <x-ui.card>
            <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" x-on:submit.prevent role="search" aria-label="{{ $onCompanies ? __('Filtrera företagen') : __('Filtrera användarna') }}">
                <div>
                    <label for="hq-customers-search" class="block text-sm font-medium text-gray-700">{{ __('Sök') }}</label>
                    <input id="hq-customers-search" type="search" wire:model.live.debounce.400ms="search" class="mt-1 {{ $input }}" placeholder="{{ $onCompanies ? __('Namn eller organisationsnummer') : __('Namn eller e-post') }}">
                </div>
                @if($onCompanies)
                    <div>
                        <label for="hq-customers-module" class="block text-sm font-medium text-gray-700">{{ __('Modul') }}</label>
                        <select id="hq-customers-module" wire:model.live="module" class="mt-1 {{ $input }}">
                            <option value="">{{ __('Alla') }}</option>
                            @foreach($moduleOptions as $key => $name)
                                <option value="{{ $key }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="hq-customers-archived" class="block text-sm font-medium text-gray-700">{{ __('Arkiverade') }}</label>
                        <select id="hq-customers-archived" wire:model.live="archived" class="mt-1 {{ $input }}">
                            <option value="">{{ __('Alla') }}</option>
                            <option value="nej">{{ __('Bara aktiva') }}</option>
                            <option value="ja">{{ __('Bara arkiverade') }}</option>
                        </select>
                    </div>
                @else
                    <div>
                        <label for="hq-customers-source" class="block text-sm font-medium text-gray-700">{{ __('Källa') }}</label>
                        <select id="hq-customers-source" wire:model.live="source" class="mt-1 {{ $input }}">
                            <option value="">{{ __('Alla') }}</option>
                            @foreach($sources as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="hq-customers-from" class="block text-sm font-medium text-gray-700">{{ __('Skapad från') }}</label>
                        <input id="hq-customers-from" type="date" wire:model.live="from" class="mt-1 {{ $input }}">
                    </div>
                    <div>
                        <label for="hq-customers-to" class="block text-sm font-medium text-gray-700">{{ __('till') }}</label>
                        <input id="hq-customers-to" type="date" wire:model.live="to" class="mt-1 {{ $input }}">
                    </div>
                </div>
                <div>
                    <label for="hq-customers-sort" class="block text-sm font-medium text-gray-700">{{ __('Ordning') }}</label>
                    <select id="hq-customers-sort" wire:model.live="sort" class="mt-1 {{ $input }}">
                        <option value="nyast">{{ __('Nyast först') }}</option>
                        <option value="aldst">{{ __('Äldst först') }}</option>
                        <option value="namn">{{ __('Namn') }}</option>
                        @if($onCompanies)
                            <option value="aktivitet">{{ __('Senaste aktivitet') }}</option>
                            <option value="verifikationer">{{ __('Flest bokförda verifikationer') }}</option>
                        @else
                            <option value="inloggning">{{ __('Senast inloggad') }}</option>
                        @endif
                    </select>
                </div>
            </form>
        </x-ui.card>

        @if($onCompanies)
            @if($companies->isEmpty())
                <x-ui.card><x-ui.empty-state :title="__('Inga företag här')">{{ __('Inget företag matchar filtret. Ändra filtret eller töm sökningen.') }}</x-ui.empty-state></x-ui.card>
            @else
                <div class="overflow-x-auto rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <caption class="sr-only">{{ __('Företagen i installationen') }}</caption>
                        <thead class="bg-stone-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">{{ __('Företag') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Organisationsnummer') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Moduler') }}</th>
                                <th scope="col" class="px-4 py-3 text-right">{{ __('Medlemmar') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Ägare') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Skapat') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Senaste aktivitet') }}</th>
                                <th scope="col" class="px-4 py-3 text-right">{{ __('Bokförda verifikationer') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($companies as $company)
                                <tr wire:key="company-{{ $company->id }}">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('hq.customers.company', $company->id) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $company->name }}</a>
                                        <span class="block text-xs text-gray-500">{{ $company->legal_form->label() }}</span>
                                        @if($company->isArchived())
                                            <x-ui.badge class="mt-1">{{ __('Arkiverat') }}</x-ui.badge>
                                        @endif
                                        @if($company->isTestCompany())
                                            <x-ui.badge class="mt-1">{{ __('Testföretag') }}</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 tabular-nums">{{ App\Support\Hq\CustomerDirectory::displayedOrgNumber($company) }}</td>
                                    <td class="px-4 py-3">{{ $company->enabled_modules === [] ? '–' : implode(', ', $company->enabled_modules) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $company->users_count + ($company->owner ? 1 : 0) }}</td>
                                    <td class="px-4 py-3">
                                        @if($company->owner)
                                            {{ $company->owner->name }}<span class="block text-xs text-gray-500 break-all">{{ $company->owner->email }}</span>
                                        @else
                                            –
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-gray-600">{{ $company->created_at?->format('Y-m-d') }}</td>
                                    <td class="px-4 py-3 tabular-nums text-gray-600">{{ $company->last_activity_at?->format('Y-m-d H:i') ?? '–' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $company->posted_vouchers_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $companies->links() }}
            @endif
        @else
            @if($users->isEmpty())
                <x-ui.card><x-ui.empty-state :title="__('Inga användare här')">{{ __('Ingen användare matchar filtret. Ändra filtret eller töm sökningen.') }}</x-ui.empty-state></x-ui.card>
            @else
                <div class="overflow-x-auto rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <caption class="sr-only">{{ __('Användarna i installationen') }}</caption>
                        <thead class="bg-stone-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">{{ __('Namn') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Företag och roll') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Källa') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Skapad') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Senast inloggad') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($users as $user)
                                <tr wire:key="user-{{ $user->id }}">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('hq.customers.user', $user->id) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $user->name }}</a>
                                        <span class="block text-xs text-gray-500 break-all">{{ $user->email }}</span>
                                        @if($user->anonymised_at)
                                            <x-ui.badge class="mt-1">{{ __('Anonymiserad') }}</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @php($memberships = $directory->companiesOf($user))
                                        @forelse($memberships as $membership)
                                            <span class="block">{{ $membership['company']->name }} <span class="text-xs text-gray-500">({{ $membership['roleLabel'] }})</span></span>
                                        @empty
                                            <span class="text-gray-500">{{ __('Inget företag') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="px-4 py-3">{{ $sources[App\Support\Hq\CustomerDirectory::sourceOf($user)] }}</td>
                                    <td class="px-4 py-3 tabular-nums text-gray-600">{{ $user->created_at?->format('Y-m-d') }}</td>
                                    <td class="px-4 py-3 tabular-nums text-gray-600">{{ $user->last_login_at?->format('Y-m-d H:i') ?? '–' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $users->links() }}
            @endif
        @endif
    </div>
</div>
