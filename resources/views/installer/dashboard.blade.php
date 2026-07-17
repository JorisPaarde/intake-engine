<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Digitale opnames
            </h2>
            <a href="{{ route('intakes.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                Nieuwe opname
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Klant</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">E-mail</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Type</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Voortgang</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Aangemaakt</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Afgerond</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-600"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($intakes as $intake)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-900">{{ $intake->customer_name }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $intake->customer_email }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $intake->templateVersion?->template?->name ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                            {{ $intake->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">{{ $intake->progress_percent }}%</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $intake->created_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $intake->completed_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">
                                            Openen
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-10 text-center text-gray-500">
                                        Nog geen opnames. Maak de eerste aan.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($intakes->hasPages())
                    <div class="border-t border-gray-100 px-4 py-3">
                        {{ $intakes->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
