<x-layouts.guest title="Reset Password">
    <div>
        <div class="mb-6">
            <h1 class="mb-2 text-title-sm font-semibold text-gray-800 dark:text-white/90">Set a new password</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Choose a new password for your account.
            </p>
        </div>

        @if ($errors->any())
            <x-ui.alert variant="error" class="mb-5">{{ $errors->first() }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div>
                <x-form.label for="email" required>Email</x-form.label>
                <x-form.input id="email" name="email" type="email" :value="old('email', $request->email)"
                    :error="$errors->has('email')" required autocomplete="username" />
            </div>

            <div>
                <x-form.label for="password" required>New password</x-form.label>
                <x-form.input id="password" name="password" type="password"
                    :error="$errors->has('password')" required autofocus autocomplete="new-password" />
            </div>

            <div>
                <x-form.label for="password_confirmation" required>Confirm new password</x-form.label>
                <x-form.input id="password_confirmation" name="password_confirmation" type="password"
                    :error="$errors->has('password_confirmation')" required autocomplete="new-password" />
            </div>

            <x-ui.button type="submit" class="w-full">Reset password</x-ui.button>
        </form>
    </div>
</x-layouts.guest>
