<x-layouts.guest title="Sign In">
    <div>
        <div class="mb-6">
            <h1 class="mb-2 text-title-sm font-semibold text-gray-800 dark:text-white/90">Sign in</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Enter your email and password to access the platform.
            </p>
        </div>

        @if (session('status'))
            <x-ui.alert variant="success" class="mb-5">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="error" class="mb-5">{{ $errors->first() }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf

            <div>
                <x-form.label for="email" required>Email</x-form.label>
                <x-form.input id="email" name="email" type="email" :value="old('email')"
                    :error="$errors->has('email')" placeholder="name@agency.de" required autofocus
                    autocomplete="username" />
            </div>

            <div>
                <x-form.label for="password" required>Password</x-form.label>
                <x-form.input id="password" name="password" type="password"
                    :error="$errors->has('password')" placeholder="Your password" required
                    autocomplete="current-password" />
            </div>

            <div class="flex items-center justify-between">
                <x-form.checkbox name="remember" label="Keep me signed in" />
                <a href="{{ route('password.request') }}"
                    class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                    Forgot password?
                </a>
            </div>

            <x-ui.button type="submit" class="w-full">Sign in</x-ui.button>
        </form>
    </div>
</x-layouts.guest>
