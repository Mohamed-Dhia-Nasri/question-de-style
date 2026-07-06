<x-layouts.guest title="Confirm Password">
    <div>
        <div class="mb-6">
            <h1 class="mb-2 text-title-sm font-semibold text-gray-800 dark:text-white/90">Confirm your password</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                This is a secure area. Please confirm your password before continuing.
            </p>
        </div>

        @if ($errors->any())
            <x-ui.alert variant="error" class="mb-5">{{ $errors->first() }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ url('/user/confirm-password') }}" class="space-y-5">
            @csrf

            <div>
                <x-form.label for="password" required>Password</x-form.label>
                <x-form.input id="password" name="password" type="password"
                    :error="$errors->has('password')" required autofocus autocomplete="current-password" />
            </div>

            <x-ui.button type="submit" class="w-full">Confirm</x-ui.button>
        </form>
    </div>
</x-layouts.guest>
