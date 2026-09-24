<?php

/** Ploi API integration; token is supplied only through the deployment environment. */
final class PloiNginx
{
    private const SITE = '/servers/121767/sites/406977';
    private const HOST = 'equi-app.staging.optimize-it.nl';

    public function __construct(private Closure $request, private Closure $readConfig, private Closure $probeRevision, private Closure $wait) {}

    public function snapshot(string $path): void
    {
        $site = ($this->request)('GET', self::SITE, null);
        if (($site['data']['domain'] ?? null) !== self::HOST) {
            throw new RuntimeException('Ploi site identity does not match the staging hostname.');
        }
        $current = ($this->request)('GET', self::SITE.'/nginx-configuration', null)['content'] ?? null;
        if (! is_string($current) || trim($current) === '') {
            throw new RuntimeException('Ploi returned an empty Nginx configuration.');
        }
        if (file_put_contents($path, $current, LOCK_EX) === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Cannot save the previous Nginx configuration.');
        }
    }

    public function apply(string $path): void
    {
        $config = file_get_contents($path);
        if ($config === false || trim($config) === '') {
            throw new RuntimeException('Missing Nginx configuration.');
        }
        $config = str_replace('__EQUI_NGINX_REVISION__', hash('sha256', $config), $config);
        preg_match('/add_header X-Equi-Nginx-Revision "([^"]+)" always;/', $config, $marker);
        $revision = $marker[1] ?? '';
        ($this->request)('PATCH', self::SITE.'/nginx-configuration', ['content' => $config]);
        // Ploi queues operations: wait for the server file, not just an HTTP 2xx from its API.
        $this->until(fn () => trim(($this->readConfig)()) === trim($config), 'Ploi did not install the requested Nginx file.');
        // Nginx gracefully validates and loads the new configuration; a failed reload
        // keeps old workers serving. The response marker proves the new workers loaded it.
        ($this->request)('POST', '/servers/121767/services/nginx/reload', null);
        $this->until(fn () => ($this->probeRevision)() === $revision, 'Nginx did not activate the requested HTTPS configuration.');
    }

    private function until(Closure $condition, string $failure): void
    {
        for ($attempt = 0; $attempt < 45; $attempt++) {
            if ($condition()) {
                return;
            }
            ($this->wait)();
        }
        throw new RuntimeException($failure);
    }

    public static function live(): self
    {
        $token = getenv('PLOI_API_TOKEN');
        if (! is_string($token) || $token === '' || strpbrk($token, "\r\n") !== false) {
            throw new RuntimeException('Set PLOI_API_TOKEN with Manage sites and Manage servers permissions.');
        }
        $request = static function (string $method, string $path, ?array $body) use ($token): array {
            $curl = curl_init('https://ploi.io/api'.$path);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer '.$token],
            ]);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            }
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($response === false || $status < 200 || $status >= 300) {
                // Do not print request headers, tokens or API response bodies into deployment logs.
                throw new RuntimeException('Ploi '.$method.' '.$path.' failed (HTTP '.$status.').');
            }

            return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        };
        $read = static function (): string {
            $path = '/etc/nginx/sites-available/'.self::HOST;
            clearstatcache(true, $path);

            return (string) file_get_contents($path);
        };
        $probe = static function (): ?string {
            $headers = [];
            $curl = curl_init('https://'.self::HOST.'/up');
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
                CURLOPT_RESOLVE => [self::HOST.':443:127.0.0.1'], CURLOPT_PROXY => '',
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    if (str_contains($line, ':')) {
                        [$key, $value] = explode(':', $line, 2);
                        $headers[strtolower(trim($key))] = trim($value);
                    }

                    return strlen($line);
                },
            ]);
            if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 0) {
                return null;
            }

            return $headers['x-equi-nginx-revision'] ?? '';
        };

        return new self($request, $read, $probe, static fn () => sleep(2));
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $action = $argv[1] ?? '';
        $path = $argv[2] ?? '';
        if (! in_array($action, ['snapshot', 'apply'], true) || $path === '') {
            throw new RuntimeException('Usage: php ploi-nginx.php snapshot|apply FILE');
        }
        PloiNginx::live()->{$action}($path);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage()."\n");
        exit(1);
    }
}
