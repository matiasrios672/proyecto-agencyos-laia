@props(['wa'])
<table class="cta" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="cta-cell" align="center">
<p class="cta-title">{{ $wa['title'] }}</p>
<p class="cta-text">{{ $wa['text'] }}</p>
<table border="0" cellpadding="0" cellspacing="0" role="presentation" align="center">
<tr>
<td>
<a href="{{ $wa['url'] }}" class="button button-whatsapp" target="_blank" rel="noopener">Escribinos por WhatsApp</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
