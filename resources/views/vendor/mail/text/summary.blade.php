@props(['items' => []])
@foreach ($items as $label => $value)
@continue(blank($value))
{{ $label }} : {{ $value }}
@endforeach
