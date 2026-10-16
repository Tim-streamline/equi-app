<?php

// Bootstrap a prepared release, but check the code that would receive traffic.
// Uploaded separately so rollback to older artifacts also runs this guard.
try {
    $bootstrap = $argv[1] ?? '';
    $target = $argv[2] ?? '';
    require $bootstrap.'/vendor/autoload.php';
    $app = require $bootstrap.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach ([
        '2026_10_14_000002_enforce_protocol_intake_invariants' => 'the required protocol/intake relationship',
        '2026_10_15_000003_unify_subscription_library_access' => 'the single subscription and separate protocol purchase model',
        '2026_10_15_000004_move_intake_photos_into_section' => 'the editable intake photo section and stable answer identifiers',
    ] as $migration => $requirement) {
        if (Illuminate\Support\Facades\Schema::hasTable('migrations')
            && Illuminate\Support\Facades\DB::table('migrations')->where('migration', $migration)->exists()
            && ! is_file($target.'/database/migrations/'.$migration.'.php')) {
            throw new RuntimeException('This code predates '.$requirement.'. Restore the matching database backup or deploy compatible code before reopening traffic.');
        }
    }

} catch (Throwable $error) {
    fwrite(STDERR, 'Schema compatibility check failed: '.$error->getMessage()."\n");
    exit(1);
}
