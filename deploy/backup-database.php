<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = config('database.connections.pgsql');
$path = $argv[1] ?? throw new RuntimeException('Backup path required.');
$process = proc_open([
    'pg_dump', '--format=custom', '--no-owner', '--no-acl',
    '--host='.$config['host'], '--port='.$config['port'],
    '--username='.$config['username'], '--dbname='.$config['database'], '--file='.$path,
], [STDIN, STDOUT, STDERR], $pipes, null, [...getenv(), 'PGPASSWORD' => $config['password']]);
if (! is_resource($process) || proc_close($process) !== 0) {
    throw new RuntimeException('Database backup failed; deployment stopped.');
}
chmod($path, 0600);
echo "Database backup complete.\n";
