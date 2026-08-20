<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="media-max-video-bytes" content="{{ config('media.limits.video') }}">
    <meta name="media-chunk-size" content="{{ config('media.chunk_size') }}">
    <title inertia>{{ config('app.name', 'EquiNova') }} Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full bg-background text-foreground antialiased">
    @inertia
</body>
</html>
