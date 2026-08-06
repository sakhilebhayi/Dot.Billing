@if ($errors->any())
    <div {{ $attributes }}>
        <div class="font-display font-semibold text-[#b3261e]">{{ __('Whoops! Something went wrong.') }}</div>

        <ul class="mt-3 list-disc list-inside text-sm text-[#b3261e]">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
