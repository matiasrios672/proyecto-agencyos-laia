@props(['url'])
<tr>
<td class="header" align="center">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ \App\Support\Brand::logoUrl() }}" class="logo" alt="{{ \App\Support\Brand::company() }}" width="190">
</a>
</td>
</tr>
<tr>
<td class="header-stripe" height="5" style="height: 5px; line-height: 5px; font-size: 5px;">&nbsp;</td>
</tr>
