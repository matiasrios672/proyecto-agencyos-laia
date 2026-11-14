@props(['motive' => null])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="\App\Support\Brand::contact()['website'] ?? config('app.url')" />
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Atención por WhatsApp según el motivo del correo --}}
@php($wa = \App\Support\Brand::whatsapp($motive))
@if ($wa)
<x-slot:cta>
<x-mail::cta :wa="$wa" />
</x-slot:cta>
@endif

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer />
</x-slot:footer>
</x-mail::layout>
