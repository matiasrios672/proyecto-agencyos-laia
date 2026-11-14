@props(['url'])
<tr>
<td class="header" align="center">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ \App\Support\Brand::logoUrl() }}" class="logo" alt="{{ \App\Support\Brand::company() }}" width="170">
</a>
@if (\App\Support\Brand::tagline())
<p class="tagline">{{ \App\Support\Brand::tagline() }}</p>
@endif
</td>
</tr>
<tr>
<td class="header-stripe" height="4" style="height: 4px; line-height: 4px; font-size: 4px;">&nbsp;</td>
</tr>
