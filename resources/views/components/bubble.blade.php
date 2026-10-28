@props([
    'variant' => 'green',
])

@php
    // Define exact classes and prevent concatenating strings for vite parsing
    $variantClasses = match($variant) {
        'green'    => 'bg-green-500 shadow-[0_0_15px_2px_var(--color-green-600)]',
        'red'      => 'bg-red-500 shadow-[0_0_15px_2px_var(--color-red-600)]',
        'blue'     => 'bg-blue-500 shadow-[0_0_15px_2px_var(--color-blue-600)]',
        'yellow'   => 'bg-yellow-500 shadow-[0_0_15px_2px_var(--color-yellow-600)]',
        'orange'   => 'bg-orange-500 shadow-[0_0_15px_2px_var(--color-orange-600)]',
        default    => 'bg-gray-500 shadow-[0_0_15px_2px_var(--color-gray-600)]',
    };
@endphp

<div x-cloak {{ $attributes->class([
    'rounded-full h-3 w-3 scale-110 transition-all duration-200 ease-in-out',
    $variantClasses,
]) }}>
    {{ $slot }}
</div>
