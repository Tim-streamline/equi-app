<?php

// Run from a prepared release, before switching the web root.
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $environment = getenv('DEPLOY_ENVIRONMENT');
    $host = getenv('DEPLOY_HOSTNAME');
    $database = getenv('DEPLOY_DATABASE');
    if (! in_array($environment, ['staging', 'production'], true) || ! $host || ! $database
        || config('app.env') !== $environment || config('app.debug') || config('database.default') !== 'pgsql'
        || rtrim(config('app.url'), '/') !== 'https://'.$host) {
        throw new RuntimeException('The release configuration does not match the selected environment.');
    }
    if (Illuminate\Support\Facades\DB::selectOne('select current_database() as name')->name !== $database) {
        throw new RuntimeException('Refusing to deploy against an unexpected database.');
    }
    if (! is_file(public_path('build/manifest.json'))) {
        throw new RuntimeException('Built admin assets are missing.');
    }
    if (! is_file(base_path('web-dist/index.html')) || ! is_dir(base_path('web-dist/powersync/worker'))) {
        throw new RuntimeException('Built customer web app or PowerSync workers are missing.');
    }
    if (! config('session.secure')) {
        throw new RuntimeException('HTTPS deployment requires SESSION_SECURE_COOKIE=true.');
    }
    echo ucfirst($environment)." environment, database connection and build verified.\n";
} catch (Throwable $error) {
    // Laravel's global CLI exception handler can render an error and return 0.
    // Deployment guards must explicitly fail so the shell stops activation.
    fwrite(STDERR, 'Release check failed: '.$error->getMessage()."\n");
    exit(1);
}
