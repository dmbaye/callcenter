@props(['tone' => 'informative'])
<table class="badge-table" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="badge badge-{{ $tone }}">{{ $slot }}</td>
</tr>
</table>
