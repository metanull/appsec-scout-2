<x-auth-layouts.auth :title="config('app.name') . ' — Sign in'" heading="Sign in to your account">

    <form method="POST" action="{{ url('/user/login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                Email address
            </label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                required
                autofocus
                autocomplete="username"
                class="fi-input block w-full rounded-lg border border-gray-300 dark:border-white/10 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-950 dark:text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-600"
            >
            @error('email')
                <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                Password
            </label>
            <input
                id="password"
                type="password"
                name="password"
                required
                autocomplete="current-password"
                class="fi-input block w-full rounded-lg border border-gray-300 dark:border-white/10 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-950 dark:text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-600"
            >
            @error('password')
                <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-2">
            <input
                id="remember"
                type="checkbox"
                name="remember"
                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
            >
            <label for="remember" class="text-sm text-gray-700 dark:text-gray-200">Remember me</label>
        </div>

        <button
            type="submit"
            class="fi-btn fi-btn-size-md fi-color-primary fi-btn-color-primary w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-600"
        >
            Sign in
        </button>
    </form>

</x-auth-layouts.auth>
