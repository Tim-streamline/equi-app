<script>
    import '@videojs/html/video/player';
    import '@videojs/html/video/minimal-skin';

    let { src, poster = '', class: className = '' } = $props();
</script>

<div
    class={`videojs-minimal overflow-hidden bg-black ${className}`}
    style="--media-border-radius: inherit; --media-color-primary: #2dd4bf;"
>
    <video-player>
        <video-minimal-skin>
            <!-- svelte-ignore a11y_media_has_caption -->
            <video {src} {poster} playsinline preload="metadata"></video>
        </video-minimal-skin>
    </video-player>
</div>

<style>
    .videojs-minimal :global(video-player),
    .videojs-minimal :global(video-minimal-skin),
    .videojs-minimal :global(video) {
        display: block;
        width: 100%;
        height: 100%;
    }

    .videojs-minimal :global(video) {
        object-fit: contain;
    }
</style>
