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
        if ($method === 'GET' && str_ends_with($path, '/406977')) return ['data' => ['domain' => 'equi-app.online']];
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
    $productionCalls = [];
    $production = new PloiNginx(function ($method, $path, $body) use (&$productionCalls) {
        $productionCalls[] = [$method, $path];
        if ($path === '/servers/123/sites/456') return ['data' => ['domain' => 'app.example.test']];
        return ['content' => 'old configuration'];
    }, fn () => str_replace('__EQUI_NGINX_REVISION__', hash('sha256', $desired), $desired),
        fn () => hash('sha256', $desired), fn () => null, '123', '456', 'app.example.test');
    $production->snapshot($directory.'/production-backup');
    $production->apply($directory.'/desired');
    check(in_array(['PATCH', '/servers/123/sites/456/nginx-configuration'], $productionCalls), 'Selected production site');
    check(in_array(['POST', '/servers/123/services/nginx/reload'], $productionCalls), 'Selected production server');

    foreach (['staging' => 'equi-app.online', 'production' => 'app.example.test'] as $environment => $host) {
        $output = $directory.'/'.$environment.'.conf';
        $process = proc_open([PHP_BINARY, __DIR__.'/render-nginx.php', __DIR__.'/nginx-staging.conf', $output, '/home/ploi/'.$host],
            [STDIN, STDOUT, STDERR], $pipes, null, [...getenv(), 'DEPLOY_ENVIRONMENT' => $environment, 'DEPLOY_HOSTNAME' => $host]);
        check(is_resource($process) && proc_close($process) === 0, 'Render selected Nginx environment');
        $rendered = file_get_contents($output);
        check(str_contains($rendered, '/home/ploi/'.$host.'/current/public'), 'Selected document root');
        check(str_contains($rendered, '/etc/nginx/ssl/'.$host), 'Selected TLS include');
        check(str_contains($rendered, 'https://'.$host.'$request_uri'), 'Selected redirects');
        if ($environment === 'staging') {
            check($rendered === file_get_contents(__DIR__.'/nginx-staging.conf'), 'Staging rendering retains tested layout');
            check(str_contains($rendered, 'server_name equi-app.online api.equi-app.online;'), 'Renamed site retains API alias');
        } else {
            check(! str_contains($rendered, 'equi-app.online'), 'Production has no staging paths');
            check(! str_contains($rendered, 'api.app.example.test'), 'Other sites do not inherit the staging API alias');
            check(! str_contains($rendered, 'X-Robots-Tag'), 'Production does not inherit staging noindex');
        }
    }
    echo "Ploi tests passed: identity, backup, delayed apply/reload, restoration, timeouts, selected production IDs and environment rendering.\n";
} finally {
    foreach (glob($directory.'/*') as $file) unlink($file);
    rmdir($directory);
}
