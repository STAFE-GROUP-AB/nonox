{{-- The pages of Personalliggare are in the sidebar (App\Support\Navigation). This button stays beside the title: at a control visit it has to be found at once. --}}
@php($slUser = auth()->user())
@if($slUser->hasTeamPermission($slUser->currentCompany(), 'staff-ledger:control-visit'))
    <a href="{{ route('staff-ledger.control-visit') }}" class="inline-flex min-h-11 items-center rounded-full bg-accent-600 px-5 py-2 text-sm font-bold text-white hover:bg-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 print:hidden">{{ __('Skatteverket är här') }}</a>
@endif
