<?php
declare(strict_types=1);
ini_set('display_errors', '0'); // never print warnings/paths to the browser
ini_set('log_errors', '1');
// Entry point for /api/* (the web server serves everything else as static files).
require dirname(__DIR__) . '/src/Api.php';
$cfg = require dirname(__DIR__) . '/lander.config.php';
$base = Rebase::normalize((string)($cfg['base_path'] ?? ''));   // e.g. /lander when Lander runs in a subfolder
$cfg['base_path'] = $base;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) $path = substr($path, strlen($base)) ?: '/';
if ($path === '/health') { header('Content-Type: application/json'); echo json_encode(['ok' => is_writable(dirname($cfg['pod'])), 'version' => 'lander-' . Api::VERSION]); exit; }
// Admin pages go through PHP so every <script> gets a one-time nonce and a strict Content-Security-Policy applies
// (the pages carry no inline event handlers: they use data-on-click, see theme.js). .htaccess routes *.html here.
if (!str_starts_with($path, '/api/') && preg_match('~^/([a-z0-9-]+)(?:\.html)?$~', $path, $pm) && is_file(__DIR__ . "/{$pm[1]}.html")) {
    $html = (string)file_get_contents(__DIR__ . "/{$pm[1]}.html");
    $nonce = base64_encode(random_bytes(16));
    $html = preg_replace('/<script\b(?![^>]*\bnonce=)/i', '<script nonce="' . $nonce . '"', $html);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce' 'strict-dynamic' 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob: https: http:; media-src 'self' blob: https: http:; frame-src 'self' https: http:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    echo $html;
    exit;
}
if (!str_starts_with($path, '/api/')) { header('Location: ' . $base . '/login.html', true, 302); exit; }
(new Api($cfg))->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
