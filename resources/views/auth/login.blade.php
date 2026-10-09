<x-guest-layout>
    @if (session('session_expired'))
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">
            <p class="font-semibold">Je sessie is verlopen.</p>
            <p class="mt-1">Log opnieuw in om verder te gaan.</p>
            @if (config('intake.demo.enabled', true))
                <p class="mt-1">Was je in de demo? Start een nieuwe demo.</p>
            @endif
        </div>
        @if (config('intake.demo.enabled', true))
            <form method="POST" action="{{ route('demo.start') }}" class="mb-6">
                @csrf
                <button
                    type="submit"
                    class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-sky-300 bg-white px-5 text-sm font-semibold text-sky-900 hover:bg-sky-50"
                >
                    Nieuwe demo starten
                </button>
            </form>
        @endif
    @else
        <x-auth-session-status class="mb-4" :status="session('status')" />
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div>
            <x-input-label for="email" value="E-mailadres" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="Wachtwoord" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">Onthoud mij</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}">
                    Wachtwoord vergeten?
                </a>
            @endif

            <x-primary-button class="ms-3">
                Inloggen
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
