<?php
declare(strict_types=1);
ini_set('display_errors', '0'); // never print warnings/paths to the browser
ini_set('log_errors', '1');
// Einstieg für /api/* (alles andere liefert der Webserver als statische Datei aus).
require dirname(__DIR__) . '/src/Api.php';
$cfg = require dirname(__DIR__) . '/lander.config.php';
$base = Rebase::normalize((string)($cfg['base_path'] ?? ''));   // z. B. /lander, wenn Lander in einem Unterordner läuft
$cfg['base_path'] = $base;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) $path = substr($path, strlen($base)) ?: '/';
if ($path === '/health') { header('Content-Type: application/json'); echo json_encode(['ok' => is_writable(dirname($cfg['pod'])), 'version' => 'lander-0.2.0']); exit; }
if (!str_starts_with($path, '/api/')) { header('Location: ' . $base . '/login.html', true, 302); exit; }
(new Api($cfg))->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
