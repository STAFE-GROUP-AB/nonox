<div class="card">
    @include('livewire.auth.partials.passkey-step', ['step' => $step])
    <form method="POST" action="{{ route('logout') }}" class="actions">
        @csrf
        <button class="link" type="submit">{{ __('Logga ut') }}</button>
    </form>
</div>
