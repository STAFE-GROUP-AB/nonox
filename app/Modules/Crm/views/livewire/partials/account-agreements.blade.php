{{-- The account's agreements (ADR-046): the Avtal tab. Needs $agreements, $account, $canManage, $now. --}}
@php($today = \Carbon\CarbonImmutable::now(App\Modules\Crm\Actions\LogActivity::ZONE)->startOfDay())
<div class="space-y-3">
    @if($canManage)
        <a href="{{ route('crm.agreements.create', ['konto' => $account->id]) }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Nytt avtal') }}</a>
    @endif
    @if($agreements->isEmpty())
        <p class="text-sm text-gray-600">{{ __('Inga avtal ännu. Lägg upp kundens avtal med period och uppsägningstid, så påminner NONOX innan det är dags att säga upp eller omförhandla.') }}</p>
    @else
        <ul class="divide-y divide-gray-100" data-testid="crm-account-agreements">
            @foreach($agreements as $agreement)
                @php($deadline = $agreement->noticeDeadline())
                @php($status = $agreement->status($today))
                <li wire:key="agreement-{{ $agreement->id }}" class="py-3">
                    <span class="flex flex-wrap items-baseline justify-between gap-2">
                        <a href="{{ route('crm.agreements.show', $agreement) }}" class="font-medium text-gray-900 hover:text-accent-700">{{ $agreement->title }}</a>
                        <x-ui.badge :tone="$status === App\Modules\Crm\Models\Agreement::ACTIVE ? 'success' : 'neutral'">{{ App\Modules\Crm\Models\Agreement::statusLabel($status) }}</x-ui.badge>
                    </span>
                    <span class="block text-sm text-gray-600 tabular-nums">
                        {{ $agreement->start_date->toDateString() }} – {{ $agreement->end_date?->toDateString() ?? __('tills vidare') }}
                        @if($deadline) · {{ __('säg upp senast :date', ['date' => $deadline->toDateString()]) }}@endif
                        @if($agreement->signed_at) · {{ __('signerat') }}@endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
