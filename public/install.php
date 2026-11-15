<?php
declare(strict_types=1);
/**
 * Lander installer: open it once in the browser after uploading. It detects whether Lander sits in a subfolder
 * (example.at/lander/) or at the root of a (sub)domain, writes lander.config.php, creates the pod and the first
 * admin, adapts the admin UI paths and deletes itself.
 */
ini_set('display_errors', '0');
$root = dirname(__DIR__);
require $root . '/src/Pod.php';
require $root . '/src/Rebase.php';

$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = Rebase::normalize(dirname($path));                 // "/lander/install.php" → "/lander", "/install.php" → ""
$cfgFile = $root . '/lander.config.php';
$checks = [
    'PHP 8.1 or newer (' . PHP_VERSION . ')' => PHP_VERSION_ID >= 80100,
    'PDO SQLite' => extension_loaded('pdo_sqlite'),
    'fileinfo (checks uploaded file types)' => extension_loaded('fileinfo'),
    'data/ is writable' => (is_dir($root . '/data') || @mkdir($root . '/data', 0750, true)) && is_writable($root . '/data'),
    'public/ is writable (paths are adapted)' => is_writable(__DIR__),
];
$ok = !in_array(false, $checks, true);
$done = false; $err = '';

// already installed? (config exists and the pod has a user): never run again
$installed = false;
if (is_file($cfgFile)) {
    try { $c = require $cfgFile; $installed = is_file($c['pod']) && (int)(new Pod($c['pod']))->db->query('SELECT COUNT(*) FROM _users')->fetchColumn() > 0; } catch (Throwable) {}
}

if ($installed) { @unlink(__FILE__); }
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $ok) {
    $user = trim((string)($_POST['user'] ?? ''));
    $pw = (string)($_POST['pw'] ?? '');
    $site = trim((string)($_POST['site'] ?? '')) ?: 'Lander';
    $origins = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($_POST['origins'] ?? '')) ?: []), fn($o) => preg_match('#^https?://[^/\s]+$#', $o)));
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $user)) $err = 'User name: 3–40 characters (letters, numbers, . _ -).';
    elseif (strlen($pw) < 10) $err = 'Password: at least 10 characters.';
    elseif ($pw !== (string)($_POST['pw2'] ?? '')) $err = 'The passwords do not match.';
    else {
        try {
            $media = $root . '/public/media';
            if (!is_dir($media)) mkdir($media, 0755, true);
            $cfg = "<?php\n// Created by install.php\nreturn " . var_export([
                'pod' => $root . '/data/content.pod', 'data_dir' => $root . '/data', 'media_dir' => $media,
                'media_url' => $base . '/media', 'base_path' => $base, 'max_upload_mb' => 12, 'max_restore_mb' => 100,
                'auto_backup_days' => 7, 'auto_backup_keep' => 8, 'public_cache_seconds' => 10,
                'form_token' => bin2hex(random_bytes(24)),
                'public_origins' => array_values(array_unique(array_merge($origins, ['http://127.0.0.1:4321', 'http://localhost:4321']))),
            ], true) . ";\n";
            file_put_contents($cfgFile, $cfg); chmod($cfgFile, 0640);
            $pod = new Pod($root . '/data/content.pod');
            $pod->run('INSERT INTO _users (id, username, password, role) VALUES (?,?,?,?)', [Pod::uuid(), $user, password_hash($pw, PASSWORD_DEFAULT), 'admin']);
            $pod->setMeta('site.name', $site);
            Rebase::run(__DIR__, $base);
            $done = true;
            @unlink(__FILE__);
        } catch (Throwable $e) { error_log('[lander install] ' . $e->getMessage()); $err = 'Installation failed: ' . $e->getMessage(); }
    }
}
$origin = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Lander</title>
<style>body{font:16px/1.5 system-ui,sans-serif;background:#0b1020;color:#dbe4ff;margin:0;padding:32px 16px}main{max-width:760px;margin:0 auto}h1{margin:0 0 4px}label{display:block;margin:14px 0 4px;font-size:14px;color:#9fb0d9}input{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #2a3560;background:#121a36;color:#fff;font:inherit}button{margin-top:20px;padding:11px 22px;border:0;border-radius:999px;background:#e0a030;color:#111;font-weight:700;cursor:pointer}.ok{color:#4ade80}.bad{color:#f87171}.card{background:#121a36;border:1px solid #2a3560;border-radius:12px;padding:16px;margin:16px 0}code{background:#0b1020;padding:2px 6px;border-radius:4px;overflow-wrap:anywhere;word-break:break-word}.cmd{display:block;margin-top:8px;padding:10px 12px;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-all}small{color:#9fb0d9}</style></head><body><main>
<h1>Lander</h1><p style="margin-top:0;color:#9fb0d9">Orbiter on PHP hosting</p>
<?php if ($installed): ?>
  <div class="card"><b>Lander is already installed.</b><br>This installer has removed itself. <a style="color:#e0a030" href="<?= $h($base) ?>/login.html">Go to the login</a>.</div>
<?php elseif ($done): ?>
  <div class="card"><b class="ok">Installed.</b> The installer has deleted itself.<br><br>
    Admin: <b><?= $h($origin . $base) ?>/login.html</b><br><small>Install location: <code><?= $h($base === '' ? '/ (root of this domain)' : $base . '/') ?></code></small></div>
  <div class="card"><b>Backups</b><br>Lander saves a backup to <code>data/backups/</code> about once a week when an admin signs in. For a fixed schedule add a cron job:<br><code class="cmd">0 3 * * * php <?= $h($root) ?>/bin/lander backup</code></div>
  <p><a style="color:#e0a030" href="<?= $h($base) ?>/login.html">Sign in →</a></p>
<?php else: ?>
  <div class="card"><b>Check</b><?php foreach ($checks as $k => $v): ?><div class="<?= $v ? 'ok' : 'bad' ?>"><?= $v ? '✓' : '✗' ?> <?= $h($k) ?></div><?php endforeach; ?>
    <small>Detected location: <code><?= $h($origin . ($base === '' ? '/' : $base . '/')) ?></code> (<?= $base === '' ? 'root of the domain' : 'subfolder' ?>)</small></div>
  <?php if ($err): ?><div class="card bad"><?= $h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?>
  <form method="post">
    <label>Site name</label><input name="site" value="<?= $h((string)($_POST['site'] ?? '')) ?>" placeholder="My site">
    <label>Admin user</label><input name="user" value="<?= $h((string)($_POST['user'] ?? 'admin')) ?>" required autocomplete="username">
    <label>Password (10+ characters)</label><input name="pw" type="password" required autocomplete="new-password">
    <label>Repeat password</label><input name="pw2" type="password" required autocomplete="new-password">
    <label>Websites that may read your content (CORS, comma separated)</label><input name="origins" value="<?= $h((string)($_POST['origins'] ?? '')) ?>" placeholder="https://example.at">
    <button>Install</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</main></body></html>
