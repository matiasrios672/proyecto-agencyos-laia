<x-mail::message>
# Hola, {{ $name }}

Gracias por tu interés en **Laia Estudio Creativo Digital**. Te compartimos el presupuesto **{{ $number }}** por un total de **{{ $total }}**.

@if($validUntil)
La propuesta es válida hasta el {{ $validUntil }}.
@endif

Adjuntamos el detalle en PDF, y también podés verlo online:

<x-mail::button :url="$url">
Ver presupuesto
</x-mail::button>

Si querés ajustar algo o tenés dudas, respondé este correo y lo vemos juntos.

Saludos,<br>
Equipo Laia
</x-mail::message>
