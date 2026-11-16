{{-- A customer in the ledger. A negative id is a Stripe customer not linked yet (ADR-080 §7.5): it links to the matching on the Stripe page. --}}
@if((int) $id < 0)
    <a href="{{ route('saas.stripe') }}#kunder" class="font-medium text-ink hover:text-accent-700 hover:underline">{{ __('Stripe-kund som inte är kopplad (#:id)', ['id' => abs((int) $id)]) }}</a>
@else
    <a href="{{ route('saas.customers.show', ['customer' => $id]) }}" class="{{ $class ?? 'font-medium text-ink hover:text-accent-700 hover:underline' }}">{{ $customer?->name ?? __('Kund :id', ['id' => $id]) }}</a>
@endif
