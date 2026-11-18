<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 42px 44px 48px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1b2a2a; font-size: 11px; line-height: 1.6; }
        .brand { color: #127a79; font-size: 10px; letter-spacing: 2px; }
        h1 { color: #127a79; font-size: 23px; line-height: 1.3; margin: 12px 0; }
        .meta { color: #627572; font-size: 10px; margin-bottom: 24px; }
        h2 { font-size: 12px; margin: 22px 0 8px; page-break-after: avoid; }
        .answer { white-space: pre-wrap; overflow-wrap: break-word; margin: 0 0 16px; }
    </style>
</head>
<body>
    <p class="brand">{{ config('app.brand_name') }} | EQUIBOOST</p>
    <h1>{{ $title }}</h1>
    <p class="meta">{{ $meta }}</p>
    @foreach ($questions as $key => $question)
        <h2>{{ $loop->iteration }}. {{ $question }}</h2>
        <p class="answer">{{ $answers[$key] ?? 'Niet ingevuld' }}</p>
    @endforeach
</body>
</html>
