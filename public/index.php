<?php
declare(strict_types=1);
// Einstieg für /api/* (alles andere liefert der Webserver als statische Datei aus).
require dirname(__DIR__) . '/src/Api.php';
$cfg = require dirname(__DIR__) . '/lander.config.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!str_starts_with($path, '/api/')) { header('Location: /login.html', true, 302); exit; }
(new Api($cfg))->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
