<?php

// Run from a prepared release, before switching the web root.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'staging' || config('app.debug') || config('database.default') !== 'pgsql'
    || config('app.url') !== 'https://equi-app.staging.optimize-it.nl') {
    throw new RuntimeException('Expected the opt-staging environment with debug disabled.');
}
if (Illuminate\Support\Facades\DB::selectOne('select current_database() as name')->name !== 'equi_app_staging') {
    throw new RuntimeException('Refusing to deploy against an unexpected database.');
}
if (! is_file(public_path('build/manifest.json'))) {
    throw new RuntimeException('Built admin assets are missing.');
}
echo "Staging environment, database connection and build verified.\n";
