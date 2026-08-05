@props([
    'user' => null,
    'alt' => '',
])

@php
    $avatarUrl = $user?->avatar_url ?? asset('images/no-image.png');
@endphp

<img
    src="{{ $avatarUrl }}"
    alt="{{ $alt }}"
    {{ $attributes->class([
        'shrink-0 rounded-full object-cover',
    ]) }}
>
