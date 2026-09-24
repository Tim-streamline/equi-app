<?php
require __DIR__.'/ploi-nginx.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function fails(Closure $test, string $message): void
{
    try {
        $test();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: '.$message);
}

$directory = sys_get_temp_dir().'/equi-ploi-test-'.bin2hex(random_bytes(4));
mkdir($directory);
try {
    $desired = 'add_header X-Equi-Nginx-Revision "__EQUI_NGINX_REVISION__" always;';
    file_put_contents($directory.'/desired', $desired);
    $installed = 'old configuration';
    $pending = null;
    $serving = '';
    $calls = [];
    $waits = 0;
    $reloadPending = false;
    $request = function ($method, $path, $body) use (&$calls, &$pending, &$installed, &$reloadPending) {
        $calls[] = [$method, $path, $body];
        if ($method === 'GET' && str_ends_with($path, '/406977')) return ['data' => ['domain' => 'equi-app.staging.optimize-it.nl']];
        if ($method === 'GET') return ['content' => $installed];
        if ($method === 'PATCH') $pending = $body['content'];
        if ($method === 'POST') $reloadPending = true;
        return ['status' => 'ok'];
    };
    $wait = function () use (&$pending, &$installed, &$serving, &$waits, &$reloadPending) {
        $waits++;
        if ($pending !== null) { $installed = $pending; $pending = null; }
        if ($reloadPending) {
            preg_match('/Revision "([^"]+)"/', $installed, $match);
            $serving = $match[1] ?? '';
            $reloadPending = false;
        }
    };
    $client = new PloiNginx($request, function () use (&$installed) { return $installed; }, function () use (&$serving) { return $serving; }, $wait);
    $client->snapshot($directory.'/backup');
    check(file_get_contents($directory.'/backup') === 'old configuration', 'Snapshot must retain original config');
    check((fileperms($directory.'/backup') & 0777) === 0600, 'Backup permissions');
    $client->apply($directory.'/desired');
    check($waits === 2, 'Wait for both file installation and loaded workers');
    check($serving === hash('sha256', $desired), 'HTTPS marker must match config');
    check($calls[2][0] === 'PATCH' && $calls[3][0] === 'POST', 'Install before graceful reload');
    $client->apply($directory.'/backup');
    check($installed === 'old configuration' && $serving === '', 'Restore legacy config without a marker');
    $wrong = new PloiNginx(fn () => ['data' => ['domain' => 'other.example']], fn () => '', fn () => '', fn () => null);
    fails(fn () => $wrong->snapshot($directory.'/wrong'), 'identity');
    $never = new PloiNginx(fn () => [], fn () => 'unchanged', fn () => '', fn () => null);
    fails(fn () => $never->apply($directory.'/desired'), 'did not install');
    $noReload = new PloiNginx(fn () => [], fn () => str_replace('__EQUI_NGINX_REVISION__', hash('sha256', $desired), $desired), fn () => 'old', fn () => null);
    fails(fn () => $noReload->apply($directory.'/desired'), 'did not activate');
    putenv('PLOI_API_TOKEN');
    fails(fn () => PloiNginx::live(), 'PLOI_API_TOKEN');
    echo "Ploi tests passed: identity, backup, delayed apply/reload, restoration, timeouts, missing token.\n";
} finally {
    foreach (glob($directory.'/*') as $file) unlink($file);
    rmdir($directory);
}
