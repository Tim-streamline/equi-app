<?php

// Render the existing tested layout for the selected Ploi site.
$source = $argv[1] ?? '';
$destination = $argv[2] ?? '';
$host = getenv('DEPLOY_HOSTNAME') ?: '';
$site = $argv[3] ?? '';
$environment = getenv('DEPLOY_ENVIRONMENT') ?: '';
if (! preg_match('/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\z/', $host)
    || $site !== '/home/ploi/'.$host || ! in_array($environment, ['staging', 'production'], true)) {
    throw new RuntimeException('Invalid Nginx deployment target.');
}
$config = file_get_contents($source);
if ($config === false) {
    throw new RuntimeException('Missing Nginx template.');
}
$config = str_replace('equi-app.online', $host, $config);
// The renamed staging site has this API alias; other sites keep their own layout.
if ($host !== 'equi-app.online') {
    $config = str_replace(' api.'.$host, '', $config);
}
if ($environment === 'production') {
    $config = str_replace('    add_header X-Robots-Tag "noindex, nofollow, noarchive" always;'."\n", '', $config);
}
if (file_put_contents($destination, $config, LOCK_EX) === false) {
    throw new RuntimeException('Cannot write Nginx configuration.');
}
