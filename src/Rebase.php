<?php
declare(strict_types=1);

/**
 * Rebase: the admin UI refers to absolute paths (/api/..., /dashboard.html, /style.css). Installed in a subfolder
 * (example.at/lander/) those need the folder as prefix. This rewrites them once, in place, and remembers the
 * current base in public/.base, so running it again (new folder, update, back to root) is safe.
 */
final class Rebase
{
    public static function normalize(string $base): string
    {
        $base = '/' . trim($base, '/');
        return $base === '/' ? '' : $base;
    }

    public static function current(string $publicDir): string
    {
        return self::normalize((string)@file_get_contents($publicDir . '/.base'));
    }

    /** @return array{files:int,changed:int,left:string[]} */
    public static function run(string $publicDir, string $newBase): array
    {
        $new = self::normalize($newBase);
        $old = self::current($publicDir);
        // every absolute path literal of the app: /api/, /health and any /<name>.html|js|css|svg (also pages Lander does not ship)
        $alt = 'api/|health\\b|[A-Za-z0-9_-]+\\.(?:html|js|css|svg)\\b';
        $oldRe = $old === '' ? '' : '(?:' . preg_quote($old, '~') . ')?';
        // a path literal: preceded by a quote, backtick, ( or =, optionally already carrying the old base
        $re = '~([\'"`(=])' . $oldRe . '/(' . $alt . ')~';
        $files = $changed = 0; $left = [];
        foreach (glob($publicDir . '/*.{html,js,css}', GLOB_BRACE) ?: [] as $f) {
            $src = (string)file_get_contents($f); $files++;
            $out = preg_replace_callback($re, fn($m) => $m[1] . $new . '/' . $m[2], $src);
            if ($out !== $src) { file_put_contents($f, $out); $changed++; }
            // anything still starting with an unprefixed known path means a pattern we did not cover
            if ($new !== '' && preg_match_all('~([\'"`(=])/(' . $alt . ')~', $out, $mm)) $left[] = basename($f) . ' (' . count($mm[0]) . ')';
        }
        // the redirect for "/" in .htaccess carries the base too
        $ht = $publicDir . '/.htaccess';
        if (is_file($ht)) {
            $h = (string)file_get_contents($ht);
            $h2 = preg_replace('~^RewriteRule \^\$ \S*/?login\.html \[R=302,L\]~m', 'RewriteRule ^$ ' . $new . '/login.html [R=302,L]', $h);
            if ($h2 !== $h) file_put_contents($ht, $h2);
        }
        file_put_contents($publicDir . '/.base', $new);
        return ['files' => $files, 'changed' => $changed, 'left' => $left];
    }
}
