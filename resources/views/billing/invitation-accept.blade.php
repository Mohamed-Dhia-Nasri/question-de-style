<x-layouts.guest title="Accept invitation">
    <div>
        <div class="mb-6">
            <h1 class="mb-2 text-title-sm font-semibold text-gray-800 dark:text-white/90">Join {{ $invitation->tenant?->name }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                You have been invited as {{ $invitation->role->label() }}. Create your account for
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $invitation->email }}</span> to accept.
            </p>
        </div>

        @if ($errors->any())
            <x-ui.alert variant="error" class="mb-5">{{ $errors->first() }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('invitations.accept', ['token' => $token]) }}" class="space-y-5">
            @csrf

            <div>
                <x-form.label for="display_name" required>Your name</x-form.label>
                <x-form.input id="display_name" name="display_name" type="text" :value="old('display_name')"
                    :error="$errors->has('display_name')" placeholder="Ada Lovelace" required autofocus />
            </div>

            <div>
                <x-form.label for="password" required>Password</x-form.label>
                <x-form.input id="password" name="password" type="password"
                    :error="$errors->has('password')" placeholder="At least 12 characters" required
                    autocomplete="new-password" />
            </div>

            <div>
                <x-form.label for="password_confirmation" required>Confirm password</x-form.label>
                <x-form.input id="password_confirmation" name="password_confirmation" type="password"
                    placeholder="Repeat the password" required autocomplete="new-password" />
            </div>

            <x-ui.button type="submit" class="w-full">Accept invitation</x-ui.button>
        </form>
    </div>
</x-layouts.guest>
