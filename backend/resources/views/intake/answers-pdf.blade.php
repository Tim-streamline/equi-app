<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<title>Kopie protocolintake | {{ config('app.brand_name') }}</title>
<style>
    @page { margin: 42px 44px 48px; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #1b2a2a; font-size: 10px; line-height: 1.5; }
    h1 { font-size: 24px; color: #127a79; margin: 4px 0 10px; }
    h2 { font-size: 16px; color: #127a79; border-bottom: 1px solid #c9dedb; padding-bottom: 7px; margin: 25px 0 14px; page-break-after: avoid; }
    h3 { font-size: 11px; margin: 16px 0 5px; page-break-after: avoid; }
    p { margin: 0 0 10px; }
    .brand { font-size: 11px; letter-spacing: 2px; color: #127a79; }
    .meta, .empty { color: #627572; }
    .answer { white-space: pre-wrap; overflow-wrap: break-word; }
    .entry { padding: 7px 10px; margin-bottom: 6px; background: #f2f6f5; }
    .entry p { margin: 0 0 5px; }
    img { max-width: 440px; max-height: 250px; }
    .photo { page-break-inside: avoid; margin-bottom: 10px; }
    ul { margin: 0 0 12px; padding-left: 18px; }
</style>
</head>
<body>
<p class="brand">{{ config('app.brand_name') }}</p>
<h1>Jouw protocolintake</h1>
<p class="meta">{{ $booking->horse?->name ?? 'Paard niet gekoppeld' }}<br>
{{ $booking->user?->name }}<br>
{{ $booking->submitted_at ? 'Ingediend op '.$booking->submitted_at->format('d-m-Y') : 'Nog niet ingediend' }}</p>
@foreach ($sections as $section)
<h2>{{ $section['nr'] + 1 }}. {{ $section['title'] }}</h2>
@foreach ($section['rows'] as $row)
<h3>{{ $row['label'] }}</h3>
@if ($row['type'] === 'sectionhead')
@elseif ($row['empty'])
<p class="empty">Niet ingevuld</p>
@elseif (in_array($row['type'], ['photo', 'file'], true))
@foreach ($row['attachments'] as $file)
<div class="photo">
@if (isset($images[$file['id']]))<img src="{{ $images[$file['id']] }}" alt="{{ $file['name'] }}"><br>@endif
{{ $file['name'] }}
</div>
@endforeach
@elseif ($row['type'] === 'repeater')
@foreach (is_array($row['value']) ? $row['value'] : [] as $entry)
<div class="entry">
@foreach ($row['sub'] as $column)
@php($value = $entry[$column['id'] ?? $column['key']] ?? '')
<p><strong>{{ $column['label'] }}:</strong> <span class="answer">{{ is_array($value) ? implode(', ', $value) : $value }}</span></p>
@endforeach
</div>
@endforeach
@elseif ($row['type'] === 'multi')
<ul>@foreach ((array) $row['value'] as $value)<li>{{ $value }}</li>@endforeach</ul>
@else
<p class="answer">{{ is_array($row['value']) ? implode("\n", $row['value']) : $row['value'] }}{{ $row['type'] === 'number' && $row['unit'] ? ' '.$row['unit'] : '' }}</p>
@endif
@endforeach
@endforeach
</body>
</html>
