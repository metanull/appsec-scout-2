<x-auth-layouts.auth :title="config('app.name') . ' — Set up two-factor authentication'" heading="Set up two-factor authentication">

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Scan the QR code with your authenticator app (e.g. Google Authenticator, Authy), then enter the 6-digit code to confirm.
    </p>

    <div class="flex justify-center py-2">
        {!! $qrCodeSvg !!}
    </div>

    <div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Cannot scan? Enter this key manually:</p>
        <code class="block rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-2 text-xs font-mono text-gray-800 dark:text-gray-200 break-all select-all">{{ $secretKey }}</code>
    </div>

    <form method="POST" action="{{ route('two-factor.setup.confirm') }}" class="space-y-4">
        @csrf

        <div>
            <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                Confirmation code
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
                placeholder="000000"
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
            Confirm and continue
        </button>
    </form>

</x-auth-layouts.auth>
