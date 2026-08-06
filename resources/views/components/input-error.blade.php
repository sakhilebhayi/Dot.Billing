@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'mt-1 text-sm text-[#b3261e]']) }}>{{ $message }}</p>
@enderror
