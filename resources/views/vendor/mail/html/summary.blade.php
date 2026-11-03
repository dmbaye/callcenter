@props(['items' => []])
{{-- Spectrum-style label / value list (receipt, invitation details). --}}
<table class="summary" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($items as $label => $value)
@continue(blank($value))
<tr class="summary-row">
<td class="summary-label">{{ $label }}</td>
<td class="summary-value">{{ $value }}</td>
</tr>
@endforeach
</table>
