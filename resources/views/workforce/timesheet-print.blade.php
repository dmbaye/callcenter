<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Timesheet') }} — {{ $team->name }} — {{ $week->format('d/m/Y') }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1f2933; font-size: 11px; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        p.meta { margin: 0 0 16px; color: #52606d; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd2d9; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f0f4f8; font-weight: 600; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .actions { margin-bottom: 16px; }
        .actions button { font: inherit; padding: 6px 12px; cursor: pointer; }
        footer { margin-top: 24px; display: flex; gap: 48px; }
        footer div { flex: 1; border-top: 1px solid #9aa5b1; padding-top: 6px; color: #52606d; }
        @media print { .actions { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('Print / save as PDF') }}</button>
    </div>
    <h1>{{ __('Timesheet') }} — {{ $team->name }}</h1>
    <p class="meta">
        {{ __('Week of :from to :to', ['from' => $week->format('d/m/Y'), 'to' => $week->addDays(6)->format('d/m/Y')]) }}
        @if ($agent) · {{ $agent->name }} @endif
        · {{ __('Printed on :date', ['date' => now()->setTimezone(config('workforce.timezone'))->format('d/m/Y H:i')]) }}
    </p>
    <table>
        <thead>
            <tr>
                @foreach ($header as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td @class(['num' => is_int($value) || is_float($value)])>{{ is_float($value) ? number_format($value, 2, ',', ' ') : $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($header) }}">{{ __('Nothing scheduled or worked this week.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <footer>
        <div>{{ __('Agent signature') }}</div>
        <div>{{ __('Supervisor signature') }}</div>
    </footer>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
