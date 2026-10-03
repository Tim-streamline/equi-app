<?php

namespace App\Support;

use Sentry\Event;

final class ErrorTracking
{
    public static function sanitize(Event $event): Event
    {
        $request = $event->getRequest();
        $safeRequest = array_intersect_key($request, array_flip(['url', 'method']));
        if (isset($safeRequest['url'])) {
            $safeRequest['url'] = self::url($safeRequest['url']);
        }
        $event->setRequest($safeRequest);
        $event->setUser(null);
        $event->setExtra([]);
        $event->setBreadcrumb([]);
        $event->setServerName(null);
        $event->setTag('component', 'backend');
        if ($event->getTransaction() !== null) {
            $event->setTransaction(self::url($event->getTransaction()));
        }
        if ($event->getMessage() !== null) {
            $event->setMessage(self::text($event->getMessage()));
        }
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::text($exception->getValue()));
        }

        return $event;
    }

    public static function url(string $url): string
    {
        $url = preg_split('/[?#]/', $url, 2)[0];
        $url = preg_replace('#(/password/reset/)[^/]+#', '$1[redacted]', $url);
        $url = preg_replace('#(/confirm(?:ation)?/)[^/]+#', '$1[redacted]', $url);

        return self::text($url);
    }

    public static function text(string $value): string
    {
        // QueryException includes interpolated SQL bindings in its message.
        $value = preg_replace('/\s*\(Connection:.*$/s', ' [SQL details omitted]', $value);
        $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $value);
        $value = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [redacted]', $value);
        $value = preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', '[token]', $value);

        return preg_replace('/((?:password|token|secret|authorization|api[_-]?key)\s*[=:]\s*)[^\s,;&]+/i', '$1[redacted]', $value);
    }
}
