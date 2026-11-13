<x-mail::message>
# Hola, {{ $name }}

@if($overdue)
Te escribimos porque el pago de **{{ $description }}** (cobro {{ $number }}) venció el {{ $dueDate }} y todavía no lo vemos acreditado.
@else
Te recordamos que el pago de **{{ $description }}** (cobro {{ $number }}) vence el **{{ $dueDate }}**.
@endif

<x-mail::panel>
**Importe:** {{ $amount }}
</x-mail::panel>

@if(count($bank))
Podés abonar por transferencia a:

@foreach($bank as $label => $value)
- **{{ $label }}:** {{ $value }}
@endforeach
@endif

Si ya realizaste el pago, avisanos respondiendo este correo con el comprobante y lo registramos. ¡Gracias!

Saludos,<br>
Equipo Laia
</x-mail::message>
