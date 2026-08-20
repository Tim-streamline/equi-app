<?php

return [
    /*
    | FilePond sends each request as a small chunk, keeping uploads below the
    | web-server and PHP request limits without buffering the complete video.
    */
    'chunk_size' => (int) env('MEDIA_CHUNK_SIZE', 5 * 1024 * 1024),
    'chunk_ttl_hours' => (int) env('MEDIA_CHUNK_TTL_HOURS', 24),

    'limits' => [
        'image' => 10 * 1024 * 1024,
        'audio' => 30 * 1024 * 1024,
        'video' => (int) env('MEDIA_MAX_VIDEO_MB', 2048) * 1024 * 1024,
    ],

    'extensions' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'aac'],
        'video' => ['mp4', 'webm', 'mov', 'm4v'],
    ],
];
