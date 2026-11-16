<?php
declare(strict_types=1);

/**
 * Outgoing webhooks (same contract as Orbiter). Hooks live in _meta 'webhooks.urls' as [{id,url,events,secret}],
 * deliveries are logged in 'webhooks.log' (last 50). A delivery is POST JSON with
 *   X-Orbiter-Event, X-Orbiter-Delivery, X-Orbiter-Timestamp, X-Orbiter-Signature: sha256=hmac(secret, "<ts>.<body>").
 * The build webhook (setting build.webhook_url, plain POST) fires on 'publish' as well.
 * ponytail: one attempt, no retry queue (a PHP request has no background timers). Add a cron-driven queue if it matters.
 */
final class Webhooks
{
    private static array $queue = [];

    public static function hooks(Pod $pod): array
    {
        $h = json_decode((string)$pod->getMeta('webhooks.urls'), true);
        return is_array($h) ? array_values(array_filter($h, fn($x) => !empty($x['url']))) : [];
    }
    public static function save(Pod $pod, array $hooks): void { $pod->setMeta('webhooks.urls', json_encode(array_values($hooks))); }
    public static function log(Pod $pod): array { $l = json_decode((string)$pod->getMeta('webhooks.log'), true); return is_array($l) ? $l : []; }

    public static function validUrl(string $u): ?string
    {
        $p = parse_url($u);
        return ($p && in_array($p['scheme'] ?? '', ['http', 'https'], true) && !empty($p['host'])) ? $u : null;
    }

    /** Queue an event; everything is sent after the response (shutdown), so the editor does not wait. */
    public static function fire(Pod $pod, string $event, array $payload = []): void
    {
        $first = !self::$queue;
        self::$queue[] = [$event, $payload];
        if ($first) register_shutdown_function(function () use ($pod) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); else { @ob_flush(); @flush(); }
            foreach (self::$queue as [$e, $p]) self::dispatch($pod, $e, $p);
        });
    }

    private static function dispatch(Pod $pod, string $event, array $payload): void
    {
        try {
            $build = trim((string)$pod->getMeta('build.webhook_url'));
            if ($build !== '' && $event === 'publish' && self::validUrl($build)) { self::post($build, '', []); $pod->setMeta('build.last_triggered', gmdate('c')); }
            foreach (self::hooks($pod) as $h) {
                if (!empty($h['events']) && !in_array($event, $h['events'], true) && !in_array('*', $h['events'], true)) continue;
                self::deliver($pod, $h, $event, $payload);
            }
        } catch (Throwable) { /* never break the request */ }
    }

    public static function deliver(Pod $pod, array $hook, string $event, array $payload): array
    {
        $id = bin2hex(random_bytes(8));
        $body = json_encode(['event' => $event, 'id' => $id, 'timestamp' => gmdate('c')] + $payload, JSON_UNESCAPED_SLASHES);
        $ts = (string)time();
        $hdr = ['Content-Type: application/json', 'User-Agent: Lander-Webhook/1.0', "X-Orbiter-Event: $event", "X-Orbiter-Delivery: $id", "X-Orbiter-Timestamp: $ts"];
        if (!empty($hook['secret'])) $hdr[] = 'X-Orbiter-Signature: sha256=' . hash_hmac('sha256', "$ts.$body", $hook['secret']);
        [$status, $err] = self::post($hook['url'], $body, $hdr);
        $entry = ['id' => $id, 'ts' => gmdate('c'), 'event' => $event, 'hook' => $hook['id'] ?? '', 'url' => $hook['url'], 'status' => $status, 'ok' => $status >= 200 && $status < 300, 'attempt' => 1, 'error' => $err];
        $pod->setMeta('webhooks.log', json_encode(array_slice(array_merge([$entry], self::log($pod)), 0, 50)));
        return $entry;
    }

    /** @return array{0:int,1:?string} */
    private static function post(string $url, string $body, array $hdr): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
            curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch) ?: null; curl_close($ch);
            return [$st, $err];
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $hdr), 'content' => $body, 'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0]]);
        $r = @file_get_contents($url, false, $ctx);
        preg_match('~HTTP/\S+ (\d+)~', $http_response_header[0] ?? '', $m);
        return [(int)($m[1] ?? 0), $r === false ? 'request failed' : null];
    }
}
