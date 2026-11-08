<x-payroll::portal-layout>
    {{-- Planering's part of Min sida; empty unless the module is on and the login is an employee. --}}
    <livewire:planning.my-schedule />
    <livewire:payroll.my-absence />
    <livewire:payroll.my-expenses />
    @if(App\Domain\Mileage\MileageLog::canLogAsEmployee(auth()->user(), auth()->user()->currentCompany()))
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Körjournal') }}</p>
                    <p class="mt-1 text-sm text-gray-700">{{ __('Lägg in resor i tjänsten och skicka in dem som milersättning.') }}</p>
                </div>
                <a href="{{ route('payroll.portal.mileage') }}" class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-900 hover:border-gray-300">{{ __('Öppna körjournalen') }}</a>
            </div>
        </x-ui.card>
    @endif
    <x-ui.page-heading :title="__('Mina lönebesked')" :lead="__('Dina lönebesked som PDF, det senaste först.')" />
    <livewire:payroll.my-payslips />
</x-payroll::portal-layout>
