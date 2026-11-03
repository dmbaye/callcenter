<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<link href="https://fonts.bunny.net/css?family=figtree:400,600,700,800&display=swap" rel="stylesheet" />
<style>
@media only screen and (max-width: 600px) {
.inner-body, .footer, .header-table { width: 100% !important; }
.content-cell { padding: 28px 20px !important; }
.wrapper-cell { padding: 24px 12px !important; }
}

@media only screen and (max-width: 500px) {
.button { width: 100% !important; text-align: center !important; }
}

{{-- Spectrum dark theme (Apple Mail, iOS Mail, Outlook.com). Inlined light styles are overridden here. --}}
@media (prefers-color-scheme: dark) {
body, .wrapper, .wrapper-cell { background-color: #131313 !important; }
.inner-body { background-color: #1e1e1e !important; border-color: #393939 !important; }
h1, h2, h3, .brand-name, .summary-value, strong { color: #f2f2f2 !important; }
p, ul, ol, li, td, .content-cell { color: #dbdbdb !important; }
.summary-label, .subcopy p, .footer p, .eyebrow { color: #afafaf !important; }
.summary-row td, .subcopy, .table th, .table td { border-color: #393939 !important; }
.panel-content { background-color: #2c2c2c !important; }
.panel-informative { background-color: #0c2175 !important; }
.panel-notice { background-color: #501b00 !important; }
.panel-negative { background-color: #5a0f0a !important; }
.panel-positive { background-color: #003c28 !important; }
.badge-informative { background-color: #0c2175 !important; color: #accffd !important; }
.badge-positive { background-color: #003c28 !important; color: #6be3a2 !important; }
.badge-notice { background-color: #501b00 !important; color: #ffc15e !important; }
.badge-negative { background-color: #5a0f0a !important; color: #ffbcb4 !important; }
.badge-neutral { background-color: #393939 !important; color: #dbdbdb !important; }
a { color: #86b7fd !important; }
.button { color: #ffffff !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="wrapper-cell" align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0">
<table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
