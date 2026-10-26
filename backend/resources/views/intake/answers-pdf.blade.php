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
    .review-answer { margin-bottom: 12px; }
    .review-answer.attention { border-left: 3px solid #b7791f; background: #fff8e9; padding: 0 10px 2px; }
    .review-answer.critical { border-left: 3px solid #a12d2d; background: #fff0ef; padding: 0 10px 2px; }
    .review-label { font-size: 9px; font-weight: bold; color: #825507; }
    .critical .review-label { color: #a12d2d; }
    .flagged-option { font-weight: bold; color: #825507; }
    .critical .flagged-option { color: #a12d2d; }
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
<div class="review-answer{{ !empty($row['critical']) ? ' critical' : (!empty($row['flagged']) ? ' attention' : '') }}">
<h3>{{ $row['label'] }}</h3>
@if (!empty($row['critical']))
<p class="review-label">Aandachtspunt · Blokkeert protocol</p>
@elseif (!empty($row['flagged']))
<p class="review-label">Aandachtspunt</p>
@endif
@if($row['owner_reported'] ?? false)<p class="muted">Vermoeden eigenaar · niet bevestigd</p>@endif
@if ($row['type'] === 'sectionhead')
@elseif (!empty($row['not_applicable']))
<p>Niet van toepassing</p>
@elseif ($row['empty'])
<p class="empty">Niet ingevuld</p>
@elseif (in_array($row['type'], ['photo', 'file'], true))
@foreach ($row['attachments'] as $file)
<div class="photo">
@if (isset($images[$file['id']]))<img src="{{ $images[$file['id']] }}" alt="{{ $file['name'] }}"><br>@endif
{{ $file['name'] }}
@if(!empty($file['year']))<br>Jaartal: {{ $file['year'] }}@endif
@if(!empty($file['slot']))<br>{{ $file['slot'] }}@endif
@if(!empty($file['caption']))<br>{{ $file['caption'] }}@endif
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
<ul>@foreach ((array) $row['value'] as $value)<li @if(in_array($value, $row['flagged_options'] ?? [], true))class="flagged-option"@endif>{{ $value }}</li>@endforeach</ul>
@else
<p class="answer">{{ is_array($row['value']) ? implode("\n", $row['value']) : $row['value'] }}{{ $row['type'] === 'number' && $row['unit'] ? ' '.$row['unit'] : '' }}</p>
@endif
</div>
@endforeach
@endforeach
<div id="intake-document-complete"></div>
</body>
</html>
