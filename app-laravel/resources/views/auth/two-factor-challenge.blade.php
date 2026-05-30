<x-auth-layouts.auth :title="config('app.name') . ' — Two-factor authentication'" heading="Two-factor authentication">

    <p class="text-sm text-gray-500 dark:text-gray-400">Enter the 6-digit code from your authenticator app.</p>

    <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                Authentication code
            </label>
            <input
                id="code"
                type="text"
                name="code"
                inputmode="numeric"
                maxlength="6"
                required
                autofocus
                autocomplete="one-time-code"
                class="fi-input block w-full rounded-lg border border-gray-300 dark:border-white/10 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-center tracking-widest text-gray-950 dark:text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-600"
            >
            @error('code')
                <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            class="fi-btn w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-600"
        >
            Verify
        </button>
    </form>

    <p class="text-center text-xs text-gray-500 dark:text-gray-400">
        Lost your device?
        <a
            href="{{ route('two-factor.login') }}"
            onclick="document.getElementById('code').name='recovery_code'; document.getElementById('code').inputMode='text'; document.getElementById('code').maxLength=191; return false;"
            class="text-primary-600 hover:text-primary-500 font-medium"
        >Use a recovery code</a>
    </p>

</x-auth-layouts.auth>
