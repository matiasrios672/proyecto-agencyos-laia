@php
    $brand = \App\Support\Brand::class;
    $contact = $brand::contact();
    $waUrl = $brand::whatsappGeneralUrl();
@endphp
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="footer-cell" align="center">
<p class="footer-brand">{{ $brand::company() }}</p>
@if ($brand::tagline())
<p class="footer-tagline">{{ $brand::tagline() }}</p>
@endif
<p class="footer-links">
@if ($contact['website'])
<a href="{{ $contact['website'] }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://#', '', rtrim($contact['website'], '/')) }}</a>
@endif
@if ($contact['email'])
&nbsp;·&nbsp; <a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>
@endif
@if ($contact['phone'])
&nbsp;·&nbsp; <a href="{{ $brand::phoneHref($contact['phone']) ?? '#' }}">{{ $contact['phone'] }}</a>
@endif
</p>
<p class="footer-links">
@if ($waUrl)
<a href="{{ $waUrl }}" target="_blank" rel="noopener">WhatsApp</a>
@endif
@if ($waUrl && $contact['instagram'])
&nbsp;·&nbsp;
@endif
@if ($contact['instagram'])
<a href="{{ $contact['instagram'] }}" target="_blank" rel="noopener">Instagram</a>
@endif
</p>
@if ($contact['address'])
<p class="footer-address">{{ $contact['address'] }}</p>
@endif
<p class="footer-legal">Recibiste este correo porque formás parte de {{ $brand::company() }} o sos cliente de la agencia.<br>© {{ date('Y') }} {{ $brand::company() }}. Todos los derechos reservados.</p>
</td>
</tr>
</table>
</td>
</tr>
