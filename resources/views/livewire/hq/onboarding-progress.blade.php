@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($yesNo = ['' => __('Alla'), 'ja' => __('Ja'), 'nej' => __('Nej')])
<div class="space-y-6">
    <x-ui.card>
        <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6" x-on:submit.prevent role="search" aria-label="{{ __('Filtrera introduktionen') }}">
            @foreach([['hq-signed-in', 'signedIn', __('Har loggat in')], ['hq-company', 'company', __('Har skapat företag')], ['hq-moved', 'movedIn', __('Bokföring inflyttad')], ['hq-invoice', 'invoice', __('Första faktura skickad')]] as [$id, $model, $label])
                <div>
                    <label for="{{ $id }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                    <select id="{{ $id }}" wire:model.live="{{ $model }}" class="mt-1 {{ $input }}">
                        @foreach($yesNo as $value => $text)
                            <option value="{{ $value }}">{{ $text }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <div>
                <label for="hq-onboarding-sort" class="block text-sm font-medium text-gray-700">{{ __('Ordning') }}</label>
                <select id="hq-onboarding-sort" wire:model.live="sort" class="mt-1 {{ $input }}">
                    <option value="hjalp">{{ __('Längst från målet först') }}</option>
                    <option value="mest">{{ __('Längst hunnit först') }}</option>
                    <option value="nyast">{{ __('Senast godkänd först') }}</option>
                    <option value="aldst">{{ __('Äldst godkänd först') }}</option>
                </select>
            </div>
            <div>
                <label for="hq-onboarding-search" class="block text-sm font-medium text-gray-700">{{ __('Sök') }}</label>
                <input id="hq-onboarding-search" type="search" wire:model.live.debounce.400ms="search" class="mt-1 {{ $input }}" placeholder="{{ __('Namn, e-post, företag') }}">
            </div>
        </form>
    </x-ui.card>

    @if($rows->isEmpty())
        <x-ui.card>
            <x-ui.empty-state :title="__('Ingen att visa')">{{ __('Ingen godkänd anmälan matchar filtret. Töm sökningen eller byt filter.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <caption class="sr-only">{{ __('Hur långt de godkända har kommit') }}</caption>
                <thead class="bg-stone-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('Företag') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Godkänd') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Inloggad') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Företag skapat') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Bokföring inflyttad') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Första faktura') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Påminnelser') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($rows as $row)
                        <tr wire:key="onboarding-{{ $row->id }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('hq.waitlist.show', $row->id) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $row->company_name }}</a>
                                <span class="block text-xs text-gray-500">{{ $row->name }}, {{ $row->email }}</span>
                                <span class="block text-xs text-gray-500">{{ $industries[$row->industry] ?? $row->industry }} · {{ $employeeRanges[$row->employees] ?? $row->employees }} · {{ $systems[$row->current_system] ?? $row->current_system }}</span>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-gray-600">{{ $row->decided_at?->format('Y-m-d') }}</td>
                            <td class="px-4 py-3"><x-ui.badge :tone="$row->signed_in ? 'success' : 'neutral'">{{ $row->signed_in ? __('Ja') : __('Nej') }}</x-ui.badge>@if($row->signed_in_at)<span class="block text-xs tabular-nums text-gray-500">{{ $row->signed_in_at->format('Y-m-d') }}</span>@endif</td>
                            <td class="px-4 py-3"><x-ui.badge :tone="$row->has_company ? 'success' : 'neutral'">{{ $row->has_company ? __('Ja') : __('Nej') }}</x-ui.badge>@if($row->company_created_at)<span class="block text-xs tabular-nums text-gray-500">{{ $row->company_created_at->format('Y-m-d') }}</span>@endif</td>
                            <td class="px-4 py-3"><x-ui.badge :tone="$row->moved_in ? 'success' : 'neutral'">{{ $row->moved_in ? __('Ja') : __('Nej') }}</x-ui.badge></td>
                            <td class="px-4 py-3"><x-ui.badge :tone="$row->invoice_sent ? 'success' : 'neutral'">{{ $row->invoice_sent ? __('Ja') : __('Nej') }}</x-ui.badge></td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                @if($row->login_reminder_sent_at)<span class="block tabular-nums">{{ __('Påminnelse om inloggning skickad') }} {{ $row->login_reminder_sent_at->format('Y-m-d') }}</span>@endif
                                @if($row->help_reminder_sent_at)<span class="block tabular-nums">{{ __('Hjälpmejl skickat') }} {{ $row->help_reminder_sent_at->format('Y-m-d') }}</span>@endif
                                @if($row->reminders_opted_out_at)<span class="block font-semibold text-gray-800">{{ __('Har tackat nej till påminnelser') }}</span>@endif
                                @if(! $row->login_reminder_sent_at && ! $row->help_reminder_sent_at && ! $row->reminders_opted_out_at)<span aria-label="{{ __('Inga påminnelser skickade') }}">–</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    @endif
</div>
