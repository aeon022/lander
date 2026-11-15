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
if ($path === '/health') { header('Content-Type: application/json'); echo json_encode(['ok' => is_writable(dirname($cfg['pod'])), 'version' => 'lander-0.2.0']); exit; }
if (!str_starts_with($path, '/api/')) { header('Location: ' . $base . '/login.html', true, 302); exit; }
(new Api($cfg))->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
