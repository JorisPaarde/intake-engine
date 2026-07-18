@php
    /** @var \App\Domains\Intake\Models\IntakeQuestion $question */
    $name = 'prefill['.$question->key.']';
    $old = old('prefill.'.$question->key);
    $inputClass = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp

<div>
    <x-input-label :value="$question->label" />
    @if ($question->help_text)
        <p class="mt-1 text-xs text-gray-500">{{ $question->help_text }}</p>
    @endif

    @switch ($question->type->value)
        @case('long_text')
            <textarea name="{{ $name }}" rows="2" class="{{ $inputClass }}">{{ $old }}</textarea>
            @break

        @case('number')
            <input type="number" inputmode="decimal" name="{{ $name }}" value="{{ $old }}" class="{{ $inputClass }}">
            @break

        @case('single_choice')
            <select name="{{ $name }}" class="{{ $inputClass }}">
                <option value="">— nog niet invullen —</option>
                @foreach ($question->options as $option)
                    <option value="{{ $option->value }}" @selected((string) $old === (string) $option->value)>{{ $option->label }}</option>
                @endforeach
            </select>
            @break

        @case('multi_choice')
            <div class="mt-1 space-y-1">
                @foreach ($question->options as $option)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="prefill[{{ $question->key }}][]" value="{{ $option->value }}"
                            @checked(is_array($old) && in_array((string) $option->value, array_map('strval', $old), true))
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        {{ $option->label }}
                    </label>
                @endforeach
            </div>
            @break

        @case('boolean')
            <select name="{{ $name }}" class="{{ $inputClass }}">
                <option value="">— nog niet invullen —</option>
                <option value="1" @selected($old === '1')>Ja</option>
                <option value="0" @selected($old === '0')>Nee</option>
            </select>
            @break

        @default
            <x-text-input name="{{ $name }}" type="text" class="mt-1 block w-full" :value="$old" />
    @endswitch

    <x-input-error :messages="$errors->get('prefill.'.$question->key)" class="mt-2" />
</div>
