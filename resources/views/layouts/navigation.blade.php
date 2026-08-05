@php
    $navCompany = Auth::user()?->company;
    $isPublicDemo = (bool) session('public_demo_mode', false)
        && str_starts_with((string) Auth::user()?->email, 'installateur+')
        && str_ends_with((string) Auth::user()?->email, '@demo.invalid')
        && str_starts_with((string) $navCompany?->slug, 'publieke-demo-');
    $publicDemoNeedsCreate = $isPublicDemo && ! session()->has('public_demo_intake_id');
@endphp
<nav x-data="{ open: false }" class="border-b border-[#D2D2D7] bg-white">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-16 justify-between">
            <div class="flex">
                <!-- Logo -->
                <div class="flex shrink-0 items-center">
                    <a href="{{ route('dashboard') }}" class="flex min-h-11 items-center gap-3 rounded-xl focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2">
                        @if ($navCompany?->hasLogo())
                            <img src="{{ route('company.logo.show', $navCompany) }}" alt="{{ $navCompany->name }}" class="h-9 w-9 rounded-lg border border-[#D2D2D7] object-contain">
                        @else
                            <x-application-logo class="block h-9 w-9 text-[var(--tenant-primary)]" />
                        @endif
                        <span class="hidden max-w-48 truncate text-sm font-semibold text-[#1D1D1F] lg:block">{{ $navCompany?->name ?? config('app.name') }}</span>
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Opnames') }}
                    </x-nav-link>
                    @if ($isPublicDemo)
                        @if ($publicDemoNeedsCreate)
                            <x-nav-link :href="route('intakes.create')" :active="request()->routeIs('intakes.create')" data-demo-anchor="nav-create">
                                {{ __('Nieuwe opname') }}
                            </x-nav-link>
                        @endif
                        <span class="inline-flex items-center border-b-2 border-sky-400 px-1 pt-1 text-sm font-semibold text-sky-700">
                            Tijdelijke demo
                        </span>
                    @else
                        <x-nav-link :href="route('intakes.create')" :active="request()->routeIs('intakes.create')">
                            {{ __('Nieuwe opname') }}
                        </x-nav-link>
                        <x-nav-link :href="route('metrics')" :active="request()->routeIs('metrics')">
                            {{ __('Resultaten') }}
                        </x-nav-link>
                        <x-nav-link :href="route('company.settings.edit')" :active="request()->routeIs('company.settings.*')">
                            {{ __('Bedrijf') }}
                        </x-nav-link>
                        @if (config('devadmin.enabled'))
                            <a href="{{ route('dev.dashboard') }}"
                               @class([
                                   'inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none',
                                   'border-amber-400 text-amber-700' => request()->routeIs('dev.*'),
                                   'border-transparent text-amber-600 hover:border-amber-300 hover:text-amber-700' => ! request()->routeIs('dev.*'),
                               ])>
                                Dev
                            </a>
                        @endif
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex min-h-11 items-center rounded-xl border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-[#424245] transition hover:text-[#1D1D1F] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        @unless ($isPublicDemo)
                            <x-dropdown-link :href="route('profile.edit')">
                                {{ __('Profiel') }}
                            </x-dropdown-link>
                        @endunless

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Uitloggen') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl p-2 text-[#424245] transition hover:bg-[#F5F5F7] hover:text-[#1D1D1F] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Opnames') }}
            </x-responsive-nav-link>
            @if ($isPublicDemo)
                @if ($publicDemoNeedsCreate)
                    <x-responsive-nav-link :href="route('intakes.create')" :active="request()->routeIs('intakes.create')">
                        {{ __('Nieuwe opname') }}
                    </x-responsive-nav-link>
                @endif
                <p class="px-4 py-2 text-sm font-semibold text-sky-700">Tijdelijke demo</p>
            @else
                <x-responsive-nav-link :href="route('intakes.create')" :active="request()->routeIs('intakes.create')">
                    {{ __('Nieuwe opname') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('metrics')" :active="request()->routeIs('metrics')">
                    {{ __('Resultaten') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('company.settings.edit')" :active="request()->routeIs('company.settings.*')">
                    {{ __('Bedrijf') }}
                </x-responsive-nav-link>
                @if (config('devadmin.enabled'))
                    <x-responsive-nav-link :href="route('dev.dashboard')" :active="request()->routeIs('dev.*')">
                        {{ __('Dev-admin') }}
                    </x-responsive-nav-link>
                @endif
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="text-base font-medium text-[#1D1D1F]">{{ Auth::user()->name }}</div>
                <div class="text-sm font-medium text-[#6E6E73]">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                @unless ($isPublicDemo)
                    <x-responsive-nav-link :href="route('profile.edit')">
                        {{ __('Profiel') }}
                    </x-responsive-nav-link>
                @endunless

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Uitloggen') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
