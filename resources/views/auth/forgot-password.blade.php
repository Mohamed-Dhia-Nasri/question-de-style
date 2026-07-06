<x-layouts.guest title="Forgot Password">
    <div>
        <div class="mb-6">
            <h1 class="mb-2 text-title-sm font-semibold text-gray-800 dark:text-white/90">Forgot your password?</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Enter your email address and we'll send you a link to reset it.
            </p>
        </div>

        @if (session('status'))
            <x-ui.alert variant="success" class="mb-5">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="error" class="mb-5">{{ $errors->first() }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
            @csrf

            <div>
                <x-form.label for="email" required>Email</x-form.label>
                <x-form.input id="email" name="email" type="email" :value="old('email')"
                    :error="$errors->has('email')" placeholder="name@agency.de" required autofocus
                    autocomplete="username" />
            </div>

            <x-ui.button type="submit" class="w-full">Email password reset link</x-ui.button>

            <p class="text-center text-sm text-gray-500 dark:text-gray-400">
                <a href="{{ route('login') }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                    Back to sign in
                </a>
            </p>
        </form>
    </div>
</x-layouts.guest>
