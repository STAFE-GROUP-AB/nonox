{{-- Archived companies apart, dimmed: they can only be read (ADR-024). --}}
@if($archived !== [])
    <section class="space-y-2" aria-labelledby="archived-companies">
        <h3 id="archived-companies" class="text-sm font-semibold text-gray-600">{{ __('Arkiverade företag') }}</h3>
        <ul class="divide-y divide-stone-200 rounded-2xl bg-white/60 ring-1 ring-stone-200">
            @foreach($archived as $row)
                @php($company = $row['company'])
                <li wire:key="archived-{{ $company->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 opacity-70">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900">
                            {{ $company->name }}
                            <x-ui.badge class="ms-2">{{ __('Arkiverat') }}</x-ui.badge>
                        </p>
                        <p class="text-sm tabular-nums text-gray-600">{{ $company->org_number }} · {{ $row['role'] }}</p>
                    </div>
                    <form method="POST" action="{{ route('current-team.update') }}">
                        @method('PUT')
                        @csrf
                        <input type="hidden" name="team_id" value="{{ $company->id }}">
                        <input type="hidden" name="till" value="dashboard">
                        <button type="submit" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna arkivet') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </section>
@endif
