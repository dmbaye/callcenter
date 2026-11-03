@props(['url'])
@php
    $logo = config('mail.brand.logo') ?: rtrim((string) config('app.url'), '/').'/images/email-logo.png';
@endphp
<tr>
<td class="header">
<table class="header-table" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header-cell">
<a href="{{ $url }}" class="brand-link" target="_blank" rel="noopener">
<img src="{{ $logo }}" class="logo" width="36" height="36" alt="">
<span class="brand-name">{!! $slot !!}</span>
</a>
</td>
</tr>
</table>
</td>
</tr>
