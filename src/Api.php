<?php
declare(strict_types=1);

require_once __DIR__ . '/Pod.php';
require_once __DIR__ . '/Backup.php';
require_once __DIR__ . '/Rebase.php';
require_once __DIR__ . '/Image.php';
require_once __DIR__ . '/Updater.php';
require_once __DIR__ . '/Totp.php';
require_once __DIR__ . '/Webhooks.php';
require_once __DIR__ . '/DigiId.php';

/**
 * Lander API: the subset of Orbiter's admin API (packages/admin/src/routes) the Station UI needs,
 * plus a public read API for websites (/api/public/...) and Markdown import.
 * Same paths, same JSON shapes as Orbiter, so the Orbiter admin pages run unchanged.
 */
final class Api
{
    public const VERSION = '0.5.0';
    private Pod $pod;
    private ?array $user = null;
    private const STATUSES = ['draft', 'in_review', 'published', 'scheduled'];
    private const META_KEYS = [
        'site.name', 'site.url', 'site.description', 'site.locale', 'site.locales',
        'media.img_max_width', 'media.img_quality', 'media.img_convert_webp',
        'dashboard.notes', 'dashboard.todos', 'dashboard.show_calendar', 'dashboard.show_recent',
        'dashboard.show_collections', 'dashboard.show_workspace', 'ui.theme', 'workflow.review',
        'format_version', 'nav.hidden', 'nav.groups', 'build.webhook_url',
    ];
    private const MEDIA_TYPES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private array $cfg) {}

    // ───────────────────────── entry point ─────────────────────────
    public function handle(string $method, string $path): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        try {
            if (str_starts_with($path, '/api/public/')) { $this->publicApi($method, substr($path, 12)); return; }
            if ($method === 'OPTIONS') { http_response_code(204); return; }
            $this->pod = new Pod($this->cfg['pod']);
            if ($method === 'POST' && preg_match('#^/api/form/([a-z0-9_-]{1,64})$#', $path, $fm)) { $this->formSubmit($fm[1]); return; }
            // Digi-ID: the wallet posts to /callback without cookies or an Origin header, so this sits before the CSRF check
            if (str_starts_with($path, '/api/digiid/')) { $this->digiidPublic($method, substr($path, 12)); return; }
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !$this->sameOrigin()) { $this->json(['error' => 'CSRF check failed'], 403); return; }
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ((int)($this->cfg[$path === '/api/restore' ? 'max_restore_mb' : 'max_upload_mb'] ?? ($path === '/api/restore' ? 100 : 12)) + 1) * 1048576) { $this->json(['error' => 'Payload too large'], 413); return; }

            if ($method === 'POST' && $path === '/api/auth/login') { $this->login(); return; }
            if ($method === 'GET' && $path === '/api/brand') { $this->json(['name' => $this->pod->getMeta('site.name'), 'product' => 'Lander']); return; }
            if ($method === 'POST' && $path === '/api/auth/logout') { $this->logout(); return; }

            $this->user = $this->pod->sessionUser($_COOKIE['orb_sess'] ?? '');
            if (!$this->user) { $this->json(['error' => 'Unauthorized'], 401); return; }
            $this->route($method, $path);
        } catch (Throwable $e) {
            error_log('[lander] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->json(['error' => 'Server error'], 500);
        }
    }

    private function route(string $m, string $p): void
    {
        $seg = array_values(array_filter(explode('/', substr($p, 5)), 'strlen')); // after /api/
        $seg = array_map('rawurldecode', $seg);
        $n = count($seg);
        $r = $seg[0] ?? '';

        match (true) {
            $r === 'auth' && ($seg[1] ?? '') === 'me' => $this->json(['user' => $this->user]),
            $r === 'info' => $this->info(),
            $r === 'meta' && $n === 1 && $m === 'GET' => $this->metaAll(),
            $r === 'meta' && $n === 1 && $m === 'PUT' => $this->metaPut(),
            $r === 'meta' && $n === 2 && $m === 'GET' => $this->metaGet($seg[1]),
            $r === 'meta' && $n === 2 && $m === 'PUT' => $this->metaPutOne($seg[1]),
            $r === 'collections' && $n === 1 && $m === 'GET' => $this->collections(),
            $r === 'collections' && $n === 1 && $m === 'POST' => $this->collectionCreate(),
            $r === 'collections' && $n === 2 && $m === 'GET' => $this->collectionGet($seg[1]),
            $r === 'collections' && $n === 2 && $m === 'PUT' => $this->collectionUpdate($seg[1]),
            $r === 'collections' && $n === 2 && $m === 'DELETE' => $this->collectionDelete($seg[1]),
            $r === 'collections' && $n === 3 && $seg[2] === 'singleton' => $this->singleton($seg[1]),
            $r === 'collections' && $n >= 3 && $seg[2] === 'entries' => $this->entries($m, $seg[1], array_slice($seg, 3)),
            $r === 'media' => $this->media($m, array_slice($seg, 1)),
            $r === 'import' && ($seg[1] ?? '') === 'markdown' && $m === 'POST' => $this->importMarkdown(),
            $r === 'forms' => $this->forms($m, array_slice($seg, 1)),
            $r === 'update' && $n === 1 && $m === 'GET' => $this->updateCheck(),
            $r === 'update' && ($seg[1] ?? '') === 'apply' && $m === 'POST' => $this->updateApply(),
            $r === 'backup' && $m === 'GET' => $this->backup(),
            $r === 'restore' && $m === 'POST' => $this->restore(),
            $r === 'users' => $this->users($m, array_slice($seg, 1)),
            $r === 'account' && ($seg[1] ?? '') === 'password' => $this->changePassword(),
            $r === 'account' && ($seg[1] ?? '') === 'username' => $this->changeUsername(),
            $r === 'account' && ($seg[1] ?? '') === 'sessions' => $this->sessions($m, $seg[2] ?? null),
            $r === 'account' && ($seg[1] ?? '') === 'digiid' => $this->digiidAccount($m, array_slice($seg, 2)),
            $r === 'account' && ($seg[1] ?? '') === '2fa' => $this->twoFactor($m, $seg[2] ?? ''),
            $r === 'api-keys' => $this->json(['keys' => []]),
            $r === 'webhooks' => $this->webhooks($m, array_slice($seg, 1)),
            $r === 'templates' => $this->json([]),
            $r === 'search' && ($seg[1] ?? '') === 'recent' => $this->recent(),
            $r === 'search' && ($seg[1] ?? '') === 'calendar' => $this->json([]),
            $r === 'search' => $this->search(),
            $r === 'locks' => $this->json(['ok' => true, 'locked' => false]),
            $r === 'quality' => $this->json(['total' => 0, 'byType' => new stdClass(), 'issues' => []]),
            $r === 'security-check' => $this->json(['findings' => []]),
            $r === 'build' => $this->json(['available' => false, 'status' => 'idle']),
            default => $this->json(['error' => 'Not available in Lander'], 501),
        };
    }

    // ───────────────────────── auth ─────────────────────────
    private function login(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$this->rateOk('login', $ip, 5, 900)) { $this->json(['error' => 'Too many login attempts. Try again in 15 minutes.'], 429); return; }
        $b = $this->body();
        $name = is_string($b['username'] ?? null) ? $b['username'] : '';
        $pass = is_string($b['password'] ?? null) ? $b['password'] : '';
        if ($name === '' || $pass === '') { $this->json(['error' => 'Missing credentials'], 400); return; }
        $u = $this->pod->userByName($name);
        // Always verify, also for unknown users, so timing does not reveal valid names.
        static $dummy = null;
        $ok = password_verify($pass, $u['password'] ?? ($dummy ??= password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)));
        if (!$u || !$ok) { $this->rateHit('login', $ip); $this->json(['error' => 'Invalid username or password'], 401); return; }
        if (!empty($u['totp_enabled']) && !$this->secondFactor($u, trim((string)($b['code'] ?? '')), $ip)) return;

        $this->startSession($u, $ip);
        if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) $this->pod->run('UPDATE _users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
        $this->json(['ok' => true, 'user' => ['id' => $u['id'], 'username' => $u['username'], 'role' => $u['role']]]);
    }

    /** GET/POST /api/webhooks, DELETE /:id, POST /:id/test (admin; secrets are shown once, never listed). */
    private function webhooks(string $m, array $seg): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Admins only'], 403); return; }
        $pub = fn($h) => ['id' => $h['id'], 'url' => $h['url'], 'events' => $h['events'] ?? ['*'], 'signed' => !empty($h['secret'])];
        $hooks = Webhooks::hooks($this->pod);
        if (!$seg && $m === 'GET') { $this->json(['hooks' => array_map($pub, $hooks), 'log' => Webhooks::log($this->pod)]); return; }
        if (!$seg && $m === 'POST') {
            $b = $this->body(); $url = Webhooks::validUrl((string)($b['url'] ?? ''));
            if (!$url) { $this->json(['error' => 'Only http(s) URLs are allowed'], 400); return; }
            $ev = array_values(array_intersect((array)($b['events'] ?? []), ['publish', 'delete', 'review', '*']));
            $h = ['id' => 'wh_' . bin2hex(random_bytes(5)), 'url' => $url, 'events' => $ev ?: ['*'], 'secret' => 'whsec_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=')];
            Webhooks::save($this->pod, [...$hooks, $h]);
            $this->json($pub($h) + ['secret' => $h['secret']], 201); return;
        }
        $id = $seg[0];
        if (count($seg) === 1 && $m === 'DELETE') { Webhooks::save($this->pod, array_filter($hooks, fn($h) => $h['id'] !== $id)); $this->json(['ok' => true]); return; }
        if (($seg[1] ?? '') === 'test' && $m === 'POST') {
            foreach ($hooks as $h) if ($h['id'] === $id) { $e = Webhooks::deliver($this->pod, $h, 'ping', ['message' => 'Test delivery from Lander']); $this->json(['ok' => $e['ok'], 'status' => $e['status'], 'error' => $e['error']]); return; }
            $this->json(['error' => 'Not found'], 404); return;
        }
        $this->json(['error' => 'Not found'], 404);
    }

    // ───────────────────────── Digi-ID ─────────────────────────
    private function host(): string { return strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '')[0])); }
    private function digiidPublic(string $m, string $act): void
    {
        $dir = rtrim($this->cfg['data_dir'], '/'); $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!DigiId::available()) { $this->json(['error' => 'Digi-ID needs the PHP gmp or bcmath extension'], 501); return; }
        if ($act === 'challenge' && $m === 'POST') {
            if (!$this->rateOk('digiid', $ip, 30, 900)) { $this->json(['error' => 'Too many requests'], 429); return; }
            $this->rateHit('digiid', $ip);
            $host = $this->host();
            if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) { $this->json(['error' => 'Bad host'], 400); return; }
            $this->json(DigiId::create($dir, 'login', null, $host, $this->https(), (string)($this->cfg['base_path'] ?? ''))); return;
        }
        if ($act === 'callback' && $m === 'POST') {
            if (!$this->rateOk('digiid-cb', $ip, 60, 900)) { $this->json(['error' => 'Too many requests'], 429); return; }
            $this->rateHit('digiid-cb', $ip);
            $r = DigiId::check($dir, $this->body());
            if (isset($r['error'])) { $this->json(['error' => $r['error']], 400); return; }
            $ch = $r['challenge']; $addr = $r['address'];
            if ($ch['purpose'] === 'login') {
                $row = $this->pod->one('SELECT u.id FROM _digiid d JOIN _users u ON u.id = d.user_id WHERE d.address = ?', [$addr]);
                if (!$row) { $ch['status'] = 'unknown'; DigiId::save($dir, $r['nonce'], $ch); $this->json(['error' => 'This address is not linked to an account'], 403); return; }
                $ch['status'] = 'ok'; $ch['userId'] = $row['id'];
            } else {
                $own = $this->pod->one('SELECT user_id FROM _digiid WHERE address = ?', [$addr]);
                if ($own && $own['user_id'] !== $ch['userId']) { $ch['status'] = 'taken'; DigiId::save($dir, $r['nonce'], $ch); $this->json(['error' => 'Address already linked to another account'], 409); return; }
                if (!$own) $this->pod->run('INSERT INTO _digiid (address, user_id) VALUES (?, ?)', [$addr, $ch['userId']]);
                $ch['status'] = 'ok';
            }
            $ch['address'] = $addr; DigiId::save($dir, $r['nonce'], $ch);
            $this->json(['address' => $addr, 'nonce' => $r['nonce']]); return;
        }
        if ($act === 'status' && $m === 'GET') {
            $nonce = (string)($_GET['nonce'] ?? ''); $ch = DigiId::load($dir, $nonce);
            if (!$ch || !hash_equals((string)$ch['poll'], (string)($_GET['poll'] ?? ''))) { $this->json(['status' => 'expired'], 404); return; }
            if ($ch['status'] === 'pending') { $this->json(['status' => 'pending']); return; }
            DigiId::drop($dir, $nonce);
            if ($ch['status'] !== 'ok') { $this->json(['status' => $ch['status']]); return; }
            if ($ch['purpose'] !== 'login') { $this->json(['status' => 'ok', 'address' => $ch['address']]); return; }
            $u = $this->pod->one('SELECT id, username, role FROM _users WHERE id = ?', [$ch['userId']]);
            if (!$u) { $this->json(['status' => 'unknown']); return; }
            $this->startSession($u, $ip);
            $this->json(['status' => 'ok', 'user' => $u]); return;
        }
        $this->json(['error' => 'Not found'], 404);
    }

    /** GET /api/account/digiid · POST link {currentPassword} · DELETE {address} {currentPassword} */
    private function digiidAccount(string $m, array $seg): void
    {
        $u = $this->pod->userByName($this->user['username']); $b = $this->body();
        $pw = fn() => password_verify((string)($b['currentPassword'] ?? ''), $u['password']);
        if (!$seg && $m === 'GET') { $this->json(['addresses' => $this->pod->all('SELECT address, created_at FROM _digiid WHERE user_id = ? ORDER BY created_at', [$u['id']])]); return; }
        if (!DigiId::available()) { $this->json(['error' => 'Digi-ID needs the PHP gmp or bcmath extension'], 501); return; }
        if (($seg[0] ?? '') === 'link' && $m === 'POST') {
            if (!$pw()) { $this->json(['error' => 'Current password is incorrect'], 401); return; }
            $this->json(DigiId::create(rtrim($this->cfg['data_dir'], '/'), 'link', $u['id'], $this->host(), $this->https(), (string)($this->cfg['base_path'] ?? ''))); return;
        }
        if (count($seg) === 1 && $m === 'DELETE' && DigiId::looksLikeAddress($seg[0])) {
            if (!$pw()) { $this->json(['error' => 'Current password is incorrect'], 401); return; }
            $this->pod->run('DELETE FROM _digiid WHERE address = ? AND user_id = ?', [$seg[0], $u['id']]); $this->json(['ok' => true]); return;
        }
        $this->json(['error' => 'Not found'], 404);
    }

    /** Checks the TOTP code (or a recovery code) at login. Answers the request itself when it fails. */
    private function secondFactor(array $u, string $code, string $ip): bool
    {
        if ($code === '') { $this->json(['error' => 'Two-factor code required', 'totp' => true], 401); return false; }
        // per-account cap on top of the per-IP one, so spread-out guessing cannot walk the 6-digit space
        if (!$this->rateOk('totp', $u['id'], 10, 900)) { $this->json(['error' => 'Too many attempts. Try again in 15 minutes.', 'totp' => true], 429); return false; }
        $step = Totp::verify((string)$u['totp_secret'], $code, (int)$u['totp_last']);
        if ($step !== null) { $this->pod->run('UPDATE _users SET totp_last = ? WHERE id = ?', [$step, $u['id']]); return true; }
        $rec = json_decode((string)$u['totp_recovery'], true) ?: []; $h = Totp::hashRecovery($code);
        if (in_array($h, $rec, true)) { $this->pod->run('UPDATE _users SET totp_recovery = ? WHERE id = ?', [json_encode(array_values(array_diff($rec, [$h]))), $u['id']]); return true; }
        $this->rateHit('totp', $u['id']); $this->rateHit('login', $ip);
        $this->json(['error' => 'Invalid code', 'totp' => true], 401);
        return false;
    }

    /** GET /api/account/2fa, POST setup | enable | disable (same contract as Orbiter). */
    private function twoFactor(string $m, string $act): void
    {
        $u = $this->pod->userByName($this->user['username']);
        $b = $this->body();
        if ($act === '' && $m === 'GET') {
            $left = !empty($u['totp_enabled']) ? count(json_decode((string)$u['totp_recovery'], true) ?: []) : 0;
            $this->json(['enabled' => !empty($u['totp_enabled']), 'recoveryLeft' => $left]); return;
        }
        if ($m !== 'POST') { $this->json(['error' => 'Method not allowed'], 405); return; }
        $pwOk = fn() => password_verify((string)($b['currentPassword'] ?? ''), $u['password']);
        if ($act === 'setup') {
            if (!empty($u['totp_enabled'])) { $this->json(['error' => '2FA is already enabled'], 409); return; }
            if (!$pwOk()) { $this->json(['error' => 'Current password is incorrect'], 401); return; }
            $sec = Totp::secret();
            $this->pod->run('UPDATE _users SET totp_secret = ?, totp_enabled = 0, totp_recovery = NULL, totp_last = 0 WHERE id = ?', [$sec, $u['id']]);
            $this->json(['secret' => $sec, 'uri' => Totp::uri($sec, $u['username'])]); return;
        }
        if ($act === 'enable') {
            $step = ($u['totp_secret'] && empty($u['totp_enabled'])) ? Totp::verify((string)$u['totp_secret'], (string)($b['code'] ?? '')) : null;
            if ($step === null) { $this->json(['error' => 'Code did not match — check the time on your phone and try again'], 400); return; }
            $codes = Totp::recoveryCodes();
            $this->pod->run('UPDATE _users SET totp_enabled = 1, totp_recovery = ?, totp_last = ? WHERE id = ?', [json_encode(array_map([Totp::class, 'hashRecovery'], $codes)), $step, $u['id']]);
            $this->pod->run('DELETE FROM _sessions WHERE user_id = ? AND token <> ?', [$u['id'], $_COOKIE['orb_sess'] ?? '']);
            $this->json(['ok' => true, 'recoveryCodes' => $codes]); return;
        }
        if ($act === 'disable') {
            if (empty($u['totp_enabled'])) { $this->json(['error' => '2FA is not enabled'], 400); return; }
            if (!$pwOk()) { $this->json(['error' => 'Current password is incorrect'], 401); return; }
            $code = (string)($b['code'] ?? ''); $rec = json_decode((string)$u['totp_recovery'], true) ?: [];
            if (Totp::verify((string)$u['totp_secret'], $code, (int)$u['totp_last']) === null && !in_array(Totp::hashRecovery($code), $rec, true)) { $this->json(['error' => 'Invalid code'], 401); return; }
            $this->pod->run('UPDATE _users SET totp_secret = NULL, totp_enabled = 0, totp_recovery = NULL, totp_last = 0 WHERE id = ?', [$u['id']]);
            $this->json(['ok' => true]); return;
        }
        $this->json(['error' => 'Not found'], 404);
    }

    /** Opens a session for $u (array with id, role) and sets the cookie. Shared by password and Digi-ID sign-in. */
    private function startSession(array $u, string $ip): void
    {
        $token = bin2hex(random_bytes(32));
        $this->pod->run("DELETE FROM _sessions WHERE expires_at < datetime('now')");
        $this->pod->run('INSERT INTO _sessions (token, user_id, expires_at, ip, ua) VALUES (?,?,?,?,?)',
            [$token, $u['id'], gmdate('Y-m-d H:i:s', time() + 30 * 86400), $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)]);
        $this->pod->run("UPDATE _users SET last_login = datetime('now') WHERE id = ?", [$u['id']]);
        if (($u['role'] ?? '') === 'admin') Backup::auto($this->cfg, $this->pod);
        setcookie('orb_sess', $token, ['expires' => time() + 30 * 86400, 'path' => $this->cookiePath(), 'httponly' => true, 'samesite' => 'Strict', 'secure' => $this->https()]);
    }

    private function logout(): void
    {
        $t = $_COOKIE['orb_sess'] ?? '';
        if ($t !== '') $this->pod->run('DELETE FROM _sessions WHERE token = ?', [$t]);
        setcookie('orb_sess', '', ['expires' => 1, 'path' => $this->cookiePath(), 'httponly' => true, 'samesite' => 'Strict', 'secure' => $this->https()]);
        $this->json(['ok' => true]);
    }

    private function changePassword(): void
    {
        $b = $this->body();
        $u = $this->pod->userByName($this->user['username']);
        $new = (string)($b['newPassword'] ?? $b['new_password'] ?? '');
        if (!password_verify((string)($b['currentPassword'] ?? $b['current_password'] ?? ''), $u['password'])) { $this->json(['error' => 'Current password is wrong'], 400); return; }
        if (strlen($new) < 10) { $this->json(['error' => 'Password must be at least 10 characters'], 400); return; }
        $this->pod->run('UPDATE _users SET password = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        $this->pod->run('DELETE FROM _sessions WHERE user_id = ? AND token <> ?', [$u['id'], $_COOKIE['orb_sess'] ?? '']);
        $this->json(['ok' => true]);
    }

    private function changeUsername(): void
    {
        $b = $this->body();
        $new = trim((string)($b['newUsername'] ?? ''));
        $u = $this->pod->userByName($this->user['username']);
        if (!password_verify((string)($b['currentPassword'] ?? ''), $u['password'])) { $this->json(['error' => 'Current password is wrong'], 400); return; }
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $new)) { $this->json(['error' => 'Username: 3–40 characters, letters, numbers, . _ -'], 400); return; }
        if ($this->pod->userByName($new)) { $this->json(['error' => 'Username already taken'], 409); return; }
        $this->pod->run('UPDATE _users SET username = ? WHERE id = ?', [$new, $u['id']]);
        $this->json(['ok' => true, 'username' => $new]);
    }

    /** List own sessions and sign out other devices (as in Orbiter: id = hash of the token, never the token itself). */
    private function sessions(string $m, ?string $id): void
    {
        $cur = $_COOKIE['orb_sess'] ?? '';
        $rows = $this->pod->all("SELECT token, ip, ua, created_at, expires_at FROM _sessions WHERE user_id = ? AND expires_at > datetime('now') ORDER BY created_at DESC", [$this->user['id']]);
        $sid = fn(string $t) => substr(hash('sha256', $t), 0, 16);
        if ($m === 'DELETE' && $id !== null) {
            foreach ($rows as $r) if ($sid($r['token']) === $id && $r['token'] !== $cur) $this->pod->run('DELETE FROM _sessions WHERE token = ?', [$r['token']]);
            $this->json(['ok' => true]); return;
        }
        $this->json(array_map(fn($r) => ['id' => $sid($r['token']), 'current' => $r['token'] === $cur, 'ip' => $r['ip'], 'ua' => $r['ua'], 'created_at' => $r['created_at'], 'expires_at' => $r['expires_at']], $rows));
    }

    // ───────────────────────── info, meta ─────────────────────────
    private function info(): void
    {
        $cols = array_map(fn($c) => [
            'id' => $c['id'], 'label' => $c['label'],
            'total' => count($this->pod->entries($c['id'], 'published')),
            'drafts' => count($this->pod->entries($c['id'], 'draft')),
            'scheduled' => count($this->pod->entries($c['id'], 'scheduled')),
            'review' => count($this->pod->entries($c['id'], 'in_review')),
            'parent' => $this->pod->getMeta("collection.{$c['id']}.parent"),
            'singleton' => (bool)$c['singleton'],
        ], $this->pod->collections());
        $hidden = $this->pod->getMeta('nav.hidden') ?? '';
        $this->json([
            'podPath' => basename($this->cfg['pod']),   // nur Dateiname, kein Serverpfad (das Dock liest ihn)
            'siteName' => $this->pod->getMeta('site.name'),
            'podSize' => @filesize($this->cfg['pod']) ?: 0,
            'formatVersion' => $this->pod->getMeta('format_version') ?? '1',
            'adminVersion' => 'Lander ' . self::VERSION . ' · UI from Orbiter 0.3.96',
            'collections' => $cols,
            'nav' => ['hidden' => array_values(array_filter(array_map('trim', explode(',', $hidden)))), 'groups' => json_decode($this->pod->getMeta('nav.groups') ?? 'null', true)],
        ]);
    }

    private function metaAllowed(string $k): bool { return in_array($k, self::META_KEYS, true) || preg_match('/^(preview_url|templates)\.[a-z0-9_-]+$/', $k) === 1; }
    private function metaAll(): void { $o = []; foreach (self::META_KEYS as $k) $o[$k] = $this->pod->getMeta($k); $this->json($o); }
    private function metaGet(string $k): void { $k = str_replace('~', '.', $k); $this->metaAllowed($k) ? $this->json(['key' => $k, 'value' => $this->pod->getMeta($k)]) : $this->json(['error' => 'Key not allowed'], 403); }
    private const META_ADMIN_ONLY = ['site.url', 'workflow.review', 'format_version', 'build.webhook_url'];
    /** Non-admins must not change site-wide settings; preview URLs are admin-only and may only be http(s) or site-relative (they end up in an <a href>). */
    private function metaWritable(string $k, mixed $v): bool
    {
        $preview = str_starts_with($k, 'preview_url.');
        if (!$this->isAdmin() && ($preview || in_array($k, self::META_ADMIN_ONLY, true))) return false;
        if ($preview && $v !== null && $v !== '' && !preg_match('#^(https?://|/(?!/))#i', trim((string)$v))) return false;
        if ($k === 'build.webhook_url' && $v !== null && $v !== '' && !Webhooks::validUrl(trim((string)$v))) return false;
        return true;
    }
    private function metaPut(): void { foreach ($this->body() as $k => $v) if ($this->metaAllowed((string)$k) && $this->metaWritable((string)$k, $v)) $this->pod->setMeta((string)$k, $v === null ? '' : (string)$v); $this->json(['ok' => true]); }
    private function metaPutOne(string $k): void
    {
        $k = str_replace('~', '.', $k);
        if (!$this->metaAllowed($k)) { $this->json(['error' => 'Key not allowed'], 403); return; }
        $v = $this->body()['value'] ?? '';
        if (!$this->metaWritable($k, $v)) { $this->json(['error' => 'Forbidden or invalid value'], 403); return; }
        $this->pod->setMeta($k, is_string($v) ? $v : json_encode($v));
        $this->json(['ok' => true]);
    }

    // ───────────────────────── collections ─────────────────────────
    private function colOut(array $c): array { $c['schema'] = json_decode($c['schema'] ?: '{}', true) ?: new stdClass(); $c['singleton'] = (bool)$c['singleton']; return $c; }

    private function collections(): void
    {
        $this->json(array_map(fn($c) => $this->colOut($c) + ['total' => count($this->pod->entries($c['id']))], $this->pod->collections()));
    }

    private function collectionGet(string $id): void { ($c = $this->pod->collection($id)) ? $this->json($this->colOut($c)) : $this->json(['error' => 'Not found'], 404); }

    private function collectionCreate(): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Forbidden'], 403); return; }
        $b = $this->body();
        $id = trim(preg_replace('/[^a-z0-9_]/', '_', strtolower((string)($b['id'] ?? ''))), '_');
        if ($id === '' || empty($b['label'])) { $this->json(['error' => 'id and label are required'], 400); return; }
        if ($this->pod->collection($id)) { $this->json(['error' => "Collection \"$id\" already exists"], 409); return; }
        $this->pod->run('INSERT INTO _collections (id, label, schema, singleton) VALUES (?,?,?,?)', [$id, (string)$b['label'], json_encode((object)($b['schema'] ?? []), JSON_UNESCAPED_UNICODE), !empty($b['singleton']) ? 1 : 0]);
        $this->json($this->colOut($this->pod->collection($id)), 201);
    }

    private function collectionUpdate(string $id): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Forbidden'], 403); return; }
        $c = $this->pod->collection($id);
        if (!$c) { $this->json(['error' => 'Not found'], 404); return; }
        $b = $this->body();
        $this->pod->run('UPDATE _collections SET label = ?, schema = ?, singleton = ? WHERE id = ?', [
            (string)($b['label'] ?? $c['label']),
            isset($b['schema']) ? json_encode((object)$b['schema'], JSON_UNESCAPED_UNICODE) : $c['schema'],
            array_key_exists('singleton', $b) ? (!empty($b['singleton']) ? 1 : 0) : $c['singleton'], $id,
        ]);
        $this->json($this->colOut($this->pod->collection($id)));
    }

    private function collectionDelete(string $id): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Forbidden'], 403); return; }
        if (!$this->pod->collection($id)) { $this->json(['error' => 'Not found'], 404); return; }
        $this->pod->run('DELETE FROM _versions WHERE entry_id IN (SELECT id FROM _entries WHERE collection_id = ?)', [$id]);
        $this->pod->run('DELETE FROM _entries WHERE collection_id = ?', [$id]);
        $this->pod->run('DELETE FROM _collections WHERE id = ?', [$id]);
        $this->json(['ok' => true]);
    }

    private function singleton(string $id): void
    {
        $c = $this->pod->collection($id);
        if (!$c || !$c['singleton']) { $this->json(['error' => 'Not found'], 404); return; }
        $e = $this->pod->entries($id);
        if (!$e) { $this->pod->createEntry($id, 'index', [], 'draft'); $e = $this->pod->entries($id); }
        $this->json($e[0]);
    }

    // ───────────────────────── entries ─────────────────────────
    private function entries(string $m, string $col, array $rest): void
    {
        if (!$this->pod->collection($col)) { $this->json(['error' => 'Collection not found'], 404); return; }
        $slug = $rest[0] ?? null; $sub = $rest[1] ?? null;
        $locale = (string)($_GET['locale'] ?? '');
        $who = $this->user['username'];

        if ($slug === null && $m === 'GET') { $this->json($this->pod->entries($col, $_GET['status'] ?? null, isset($_GET['locale']) ? $locale : null)); return; }
        if ($slug === null && $m === 'POST') {
            $b = $this->body(); $s = $this->slugify((string)($b['slug'] ?? '')); $st = (string)($b['status'] ?? 'draft'); $lc = (string)($b['locale'] ?? '');
            if ($s === '') { $this->json(['error' => 'slug is required'], 400); return; }
            if (!in_array($st, self::STATUSES, true)) { $this->json(['error' => 'Invalid status'], 400); return; }
            if ($this->pod->entry($col, $s, $lc)) { $this->json(['error' => "Entry \"$s\" already exists"], 409); return; }
            $id = $this->pod->createEntry($col, $s, (array)($b['data'] ?? []), $st, $lc);
            $this->pod->audit($id, $who, 'create');
            if ($st === 'published') Webhooks::fire($this->pod, 'publish', ['collection' => $col, 'slug' => $s]);
            $this->json($this->pod->entry($col, $s, $lc), 201); return;
        }
        if ($slug === 'reorder' && $m === 'PATCH') {
            foreach ((array)($this->body()['slugs'] ?? []) as $i => $s) $this->pod->run('UPDATE _entries SET sort_order = ? WHERE collection_id = ? AND slug = ?', [$i, $col, (string)$s]);
            $this->json(['ok' => true]); return;
        }
        if ($slug === 'bulk' && $m === 'POST') { $this->bulk($col); return; }

        if ($sub === null && $m === 'GET') { ($e = $this->pod->entry($col, $slug, $locale)) ? $this->json($e) : $this->json(['error' => 'Not found'], 404); return; }
        if ($sub === null && $m === 'PUT') {
            $b = $this->body(); $lc = (string)($b['locale'] ?? $locale);
            if (isset($b['status']) && !in_array($b['status'], self::STATUSES, true)) { $this->json(['error' => 'Invalid status'], 400); return; }
            if (isset($b['slug'])) $b['slug'] = $this->slugify((string)$b['slug']) ?: $slug;
            $before = $this->pod->entry($col, $slug, $lc);
            if (!$this->pod->updateEntry($col, $slug, $b + ['locale' => $lc])) { $this->json(['error' => 'Not found'], 404); return; }
            $after = $this->pod->entry($col, $b['slug'] ?? $slug, $lc);
            $pub = ($b['status'] ?? null) === 'published' && ($before['status'] ?? '') !== 'published';
            $this->pod->audit($after['id'], $who, $pub ? 'publish' : 'update');
            if ($pub) Webhooks::fire($this->pod, 'publish', ['collection' => $col, 'slug' => $after['slug']]);
            $this->json($after); return;
        }
        if ($sub === null && $m === 'DELETE') {
            $e = $this->pod->entry($col, $slug, $locale);
            if (!$e) { $this->json(['error' => 'Not found'], 404); return; }
            $this->pod->run('UPDATE _entries SET deleted_at = ? WHERE id = ?', [Pod::now(), $e['id']]);
            $this->pod->audit($e['id'], $who, 'delete');
            Webhooks::fire($this->pod, 'delete', ['collection' => $col, 'slug' => $slug]);
            $this->json(['ok' => true]); return;
        }
        if ($sub === 'restore' && $m === 'POST') {
            $n = $this->pod->run("UPDATE _entries SET deleted_at = NULL, status = 'draft' WHERE collection_id = ? AND slug = ? AND locale = ? AND deleted_at IS NOT NULL", [$col, $slug, $locale]);
            $n ? $this->json(['ok' => true]) : $this->json(['error' => 'Not found'], 404); return;
        }
        if ($sub === 'permanent' && $m === 'DELETE') {
            $r = $this->pod->one('SELECT id FROM _entries WHERE collection_id = ? AND slug = ? AND locale = ?', [$col, $slug, $locale]);
            if ($r) { $this->pod->run('DELETE FROM _versions WHERE entry_id = ?', [$r['id']]); $this->pod->run('DELETE FROM _entries WHERE id = ?', [$r['id']]); }
            $this->json(['ok' => true]); return;
        }
        if ($sub === 'status' && $m === 'PATCH') {
            $st = (string)($this->body()['status'] ?? '');
            if (!in_array($st, self::STATUSES, true)) { $this->json(['error' => 'Invalid status'], 400); return; }
            $this->pod->updateEntry($col, $slug, ['status' => $st, 'locale' => $locale]) ? $this->json($this->pod->entry($col, $slug, $locale)) : $this->json(['error' => 'Not found'], 404); return;
        }
        if ($sub === 'duplicate' && $m === 'POST') {
            $e = $this->pod->entry($col, $slug, $locale);
            if (!$e) { $this->json(['error' => 'Not found'], 404); return; }
            $new = $slug . '-kopie'; $i = 2; while ($this->pod->entry($col, $new, $locale)) $new = $slug . '-kopie-' . $i++;
            $this->pod->createEntry($col, $new, $e['data'], 'draft', $locale);
            $this->json($this->pod->entry($col, $new, $locale), 201); return;
        }
        $e = $this->pod->entry($col, $slug, $locale);
        if ($sub === 'versions' && $e) {
            $vid = $rest[2] ?? null;
            if ($vid === null) { $this->json($this->pod->all('SELECT id, created_at FROM _versions WHERE entry_id = ? ORDER BY created_at DESC', [$e['id']])); return; }
            $v = $this->pod->one('SELECT * FROM _versions WHERE id = ? AND entry_id = ?', [$vid, $e['id']]);
            if (!$v) { $this->json(['error' => 'Not found'], 404); return; }
            if (($rest[3] ?? '') === 'restore' && $m === 'POST') { $this->pod->updateEntry($col, $slug, ['data' => json_decode($v['data'], true), 'locale' => $locale]); $this->json(['ok' => true]); return; }
            $v['data'] = json_decode($v['data'], true); $this->json($v); return;
        }
        if ($sub === 'activity' && $e) { $this->json($this->pod->all('SELECT username, action, created_at FROM _audit WHERE entry_id = ? ORDER BY created_at DESC LIMIT 50', [$e['id']])); return; }
        if ($sub === 'locales' && $m === 'GET') { $this->json($this->pod->all('SELECT locale, status, updated_at FROM _entries WHERE collection_id = ? AND slug = ? AND deleted_at IS NULL', [$col, $slug])); return; }
        if ($sub === 'comments') { $this->json([]); return; }
        $this->json(['error' => 'Not available in Lander'], 501);
    }

    private function bulk(string $col): void
    {
        $b = $this->body(); $slugs = array_map('strval', (array)($b['slugs'] ?? [])); $a = (string)($b['action'] ?? ''); $lc = (string)($b['locale'] ?? '');
        foreach ($slugs as $s) {
            match ($a) {
                'publish', 'draft' => $this->pod->updateEntry($col, $s, ['status' => $a === 'publish' ? 'published' : 'draft', 'locale' => $lc]),
                'delete' => $this->pod->run('UPDATE _entries SET deleted_at = ? WHERE collection_id = ? AND slug = ? AND locale = ? AND deleted_at IS NULL', [Pod::now(), $col, $s, $lc]),
                'restore' => $this->pod->run("UPDATE _entries SET deleted_at = NULL, status = 'draft' WHERE collection_id = ? AND slug = ? AND locale = ?", [$col, $s, $lc]),
                'permanent' => $this->pod->run('DELETE FROM _entries WHERE collection_id = ? AND slug = ? AND locale = ?', [$col, $s, $lc]),
                default => null,
            };
        }
        if ($a === 'publish') Webhooks::fire($this->pod, 'publish', ['collection' => $col, 'action' => 'publish', 'count' => count($slugs)]);
        if ($a === 'delete') Webhooks::fire($this->pod, 'delete', ['collection' => $col, 'action' => 'delete', 'count' => count($slugs)]);
        $this->json(['ok' => true, 'count' => count($slugs)]);
    }

    // ───────────────────────── media (files in a folder) ─────────────────────────
    private function media(string $m, array $seg): void
    {
        $id = $seg[0] ?? null;
        if ($id === null && $m === 'GET') {
            $f = $_GET['folder'] ?? null;
            $sql = 'SELECT id, filename, mime_type, size, alt, folder, url, focal_x, focal_y, created_at FROM _media' . ($f !== null ? ' WHERE folder = ?' : '') . ' ORDER BY created_at DESC';
            $this->json($this->pod->all($sql, $f !== null ? [$f] : [])); return;
        }
        if ($id === 'usage') { $this->json(['usage' => new stdClass(), 'unused' => [], 'broken' => []]); return; }
        if ($id === null && $m === 'POST') { $this->upload(); return; }
        $item = $id ? $this->pod->one('SELECT * FROM _media WHERE id = ?', [$id]) : null;
        if (!$item) { $this->json(['error' => 'Not found'], 404); return; }
        if (($seg[1] ?? '') === 'raw') { if (Image::wanted() && Image::serve($item, rtrim($this->cfg['data_dir'], '/') . '/cache/media')) return; header('Location: ' . $item['url'], true, 302); return; }
        if ($m === 'PUT') {
            $b = $this->body();
            if (array_key_exists('alt', $b)) $this->pod->run('UPDATE _media SET alt = ? WHERE id = ?', [$b['alt'] === null ? null : mb_substr((string)$b['alt'], 0, 500), $id]);
            if (array_key_exists('focal_x', $b) || array_key_exists('focal_y', $b)) $this->pod->run('UPDATE _media SET focal_x = ?, focal_y = ? WHERE id = ?', [$b['focal_x'] ?? null, $b['focal_y'] ?? null, $id]);
            $this->json(['ok' => true]); return;
        }
        if ($m === 'DELETE') {
            if ($item['path'] && is_file($item['path']) && str_starts_with(realpath($item['path']) ?: '', realpath($this->cfg['media_dir']) ?: "\0")) unlink($item['path']);
            $this->pod->run('DELETE FROM _media WHERE id = ?', [$id]);
            $this->json(['ok' => true]); return;
        }
        $this->json(['error' => 'Not available in Lander'], 501);
    }

    private function upload(): void
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) { $this->json(['error' => 'No file provided'], 400); return; }
        if ($f['size'] > ($this->cfg['max_upload_mb'] ?? 12) * 1048576) { $this->json(['error' => 'File too large'], 413); return; }
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';   // real type, not the browser's claim
        if (!isset(self::MEDIA_TYPES[$type])) { $this->json(['error' => 'File type not allowed (JPG, PNG, WebP, GIF, AVIF, PDF)'], 415); return; }

        $folder = trim(preg_replace('/[^a-z0-9_\/-]/', '', strtolower((string)($_POST['folder'] ?? ''))), '/');
        $folder = implode('/', array_filter(explode('/', $folder), fn($p) => $p !== '' && $p !== '..'));
        $id = Pod::uuid();
        $base = $this->slugify(pathinfo((string)$f['name'], PATHINFO_FILENAME)) ?: 'datei';
        $name = substr($id, 0, 8) . '-' . substr($base, 0, 60) . '.' . self::MEDIA_TYPES[$type];
        $dir = rtrim($this->cfg['media_dir'], '/') . ($folder !== '' ? "/$folder" : '');
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) { $this->json(['error' => 'Cannot create media folder'], 500); return; }
        $path = "$dir/$name";
        if (!move_uploaded_file($f['tmp_name'], $path)) { $this->json(['error' => 'Upload failed'], 500); return; }
        chmod($path, 0644);
        $url = rtrim($this->cfg['media_url'], '/') . ($folder !== '' ? "/$folder" : '') . "/$name";
        $this->pod->run('INSERT INTO _media (id, filename, mime_type, size, data, alt, folder, url, path) VALUES (?,?,?,?,NULL,?,?,?,?)',
            [$id, $name, $type, filesize($path), $_POST['alt'] ?? null, $folder, $url, $path]);
        $this->json($this->pod->one('SELECT id, filename, mime_type, size, alt, folder, url, focal_x, focal_y, created_at FROM _media WHERE id = ?', [$id]), 201);
    }

    // ───────────────────────── update check (admin) ─────────────────────────
    /** GET /api/update[?refresh=1]: is a newer Lander release on GitHub? Cached 12 h; off with 'update_check' => false. Only reads, never changes files. */
    private function updateCheck(): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Admins only'], 403); return; }
        $out = ['current' => self::VERSION, 'latest' => null, 'available' => false, 'url' => null, 'notes' => null, 'checkedAt' => null, 'enabled' => ($this->cfg['update_check'] ?? true) !== false, 'canApply' => false, 'whyNot' => []];
        $cap = Updater::capability(dirname(__DIR__)); $out['canApply'] = $cap['ok']; $out['whyNot'] = $cap['why'];
        if (!$out['enabled']) { $this->json($out); return; }
        $cache = rtrim($this->cfg['data_dir'], '/') . '/update-check.json';
        $c = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
        if (!is_array($c) || (time() - (int)($c['at'] ?? 0)) > 43200 || isset($_GET['refresh'])) {
            $c = ['at' => time(), 'tag' => null, 'url' => null, 'notes' => null, 'error' => null];
            $url = 'https://api.github.com/repos/aeon022/lander/releases/latest';
            $hdr = "User-Agent: Lander/" . self::VERSION . "\r\nAccept: application/vnd.github+json\r\n";
            $body = null;
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => explode("\r\n", trim($hdr)), CURLOPT_SSL_VERIFYPEER => true]);
                $r = curl_exec($ch); if ($r !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $body = $r; curl_close($ch);
            } elseif (ini_get('allow_url_fopen')) {
                $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6, 'header' => $hdr]]));
                if ($r !== false) $body = $r;
            }
            $j = $body ? json_decode($body, true) : null;
            if (is_array($j) && isset($j['tag_name'])) { $c['tag'] = (string)$j['tag_name']; $c['url'] = (string)($j['html_url'] ?? ''); $c['notes'] = mb_substr((string)($j['body'] ?? ''), 0, 800); }
            else $c['error'] = 'GitHub is not reachable from this server';
            @file_put_contents($cache, json_encode($c));
        }
        $latest = isset($c['tag']) ? ltrim((string)$c['tag'], 'vV') : null;
        $out['latest'] = $latest; $out['url'] = $c['url'] ?? null; $out['notes'] = $c['notes'] ?? null; $out['checkedAt'] = gmdate('c', (int)($c['at'] ?? time()));
        $out['available'] = $latest !== null && preg_match('/^\d+(\.\d+){1,3}$/', $latest) === 1 && version_compare($latest, self::VERSION, '>');
        if (!empty($c['error'])) $out['error'] = $c['error'];
        $this->json($out);
    }

    /** POST /api/update/apply: signed one-click update (see Updater). Admins only. */
    private function updateApply(): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Admins only'], 403); return; }
        if (($this->cfg['update_check'] ?? true) === false) { $this->json(['error' => 'Updates are switched off (update_check)'], 403); return; }
        try { $r = Updater::apply($this->cfg, $this->pod, dirname(__DIR__), self::VERSION); }
        catch (Throwable $e) { $this->json(['error' => $e->getMessage()], 400); return; }
        @unlink(rtrim($this->cfg['data_dir'], '/') . '/update-check.json');
        $this->json(['ok' => true] + $r);
    }

    // ───────────────────────── users (admin) ─────────────────────────
    /** GET/POST /api/users, PUT/DELETE /api/users/{id}. Roles: admin, editor (no reviewer, no per-collection limits, no 2FA yet). */
    private function users(string $m, array $seg): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Admins only'], 403); return; }
        $id = $seg[0] ?? null;
        if ($id === null && $m === 'GET') { $this->json($this->pod->all('SELECT id, username, role, created_at, last_login, totp_enabled FROM _users ORDER BY created_at')); return; }
        if ($id === null && $m === 'POST') {
            $b = $this->body();
            $name = is_string($b['username'] ?? null) ? trim($b['username']) : '';
            $pass = is_string($b['password'] ?? null) ? $b['password'] : '';
            $role = ($b['role'] ?? 'editor') === 'admin' ? 'admin' : 'editor';
            if ($name === '' || $pass === '') { $this->json(['error' => 'username and password are required'], 400); return; }
            if (!preg_match('/^[\p{L}\p{N}._@+-]{2,64}$/u', $name)) { $this->json(['error' => 'Username: 2–64 characters, letters, digits and . _ - @ + only'], 400); return; }
            if (strlen($pass) < 10) { $this->json(['error' => 'Password must be at least 10 characters'], 400); return; }
            if ($this->pod->userByName($name)) { $this->json(['error' => "User \"$name\" already exists"], 409); return; }
            $uid = Pod::uuid();
            $this->pod->run('INSERT INTO _users (id, username, password, role) VALUES (?,?,?,?)', [$uid, $name, password_hash($pass, PASSWORD_DEFAULT), $role]);
            $this->json(['ok' => true, 'id' => $uid, 'username' => $name, 'role' => $role], 201); return;
        }
        $target = $id ? $this->pod->one('SELECT id, username, role FROM _users WHERE id = ?', [$id]) : null;
        if (!$target) { $this->json(['error' => 'User not found'], 404); return; }
        $self = $target['id'] === $this->user['id'];
        if (($seg[1] ?? '') === 'permissions') {   // per-collection limits are not available: always unrestricted
            if ($m === 'GET') { $this->json(['allowed' => null]); return; }
            $this->json(['error' => 'Not available in Lander'], 501); return;
        }
        if ($m === 'PUT') {
            $b = $this->body();
            if (isset($b['role'])) {
                if ($self) { $this->json(['error' => 'Cannot change your own role'], 400); return; }
                $this->pod->run('UPDATE _users SET role = ? WHERE id = ?', [$b['role'] === 'admin' ? 'admin' : 'editor', $id]);
            }
            if (isset($b['password']) && $b['password'] !== '') {
                if (!is_string($b['password']) || strlen($b['password']) < 10) { $this->json(['error' => 'Password must be at least 10 characters'], 400); return; }
                $this->pod->run('UPDATE _users SET password = ? WHERE id = ?', [password_hash($b['password'], PASSWORD_DEFAULT), $id]);
                $this->pod->run('DELETE FROM _sessions WHERE user_id = ? AND token <> ?', [$id, $self ? ($_COOKIE['orb_sess'] ?? '') : '']);
            }
            $this->json(['ok' => true]); return;
        }
        if ($m === 'DELETE') {
            if ($self) { $this->json(['error' => 'Cannot delete your own account'], 400); return; }
            $this->pod->run('DELETE FROM _sessions WHERE user_id = ?', [$id]);
            $this->pod->run('DELETE FROM _users WHERE id = ?', [$id]);
            $this->json(['ok' => true]); return;
        }
        $this->json(['error' => 'Not available in Lander'], 501);
    }

    // ───────────────────────── backup / restore (admin) ─────────────────────────
    private function backup(): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Admins only'], 403); return; }
        Backup::download($this->cfg, $this->pod, ($_GET['media'] ?? '') === '1');
    }

    private function restore(): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Admins only'], 403); return; }
        $f = $_FILES['file'] ?? null;
        if (!$f && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { $this->json(['error' => 'File exceeds the PHP limit (post_max_size / upload_max_filesize): raise it in php.ini or .user.ini'], 413); return; }
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $tooBig = in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            $this->json(['error' => $tooBig ? 'File exceeds the PHP upload limit (upload_max_filesize / post_max_size)' : 'No file provided'], $tooBig ? 413 : 400); return;
        }
        $res = Backup::restore($this->cfg, $this->pod, $f['tmp_name']);
        $this->json($res, $res['ok'] ? 200 : 422);
    }

    // ───────────────────────── markdown import ─────────────────────────
    private function importMarkdown(): void
    {
        if (!$this->isAdmin()) { $this->json(['error' => 'Forbidden'], 403); return; }
        $col = (string)($_POST['md_collection'] ?? '');
        $dup = (string)($_POST['on_duplicate'] ?? 'skip');
        if (!$this->pod->collection($col)) { $this->json(['error' => "Collection \"$col\" not found"], 404); return; }
        $files = $_FILES['md_files'] ?? null;
        if (!$files) { $this->json(['error' => 'No files provided'], 400); return; }
        $names = (array)$files['name']; $tmps = (array)$files['tmp_name']; $errs = (array)$files['error'];
        $imported = 0; $skipped = 0;
        foreach ($tmps as $i => $tmp) {
            if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            [$data, $body] = self::frontmatter((string)file_get_contents($tmp));
            $slug = $this->slugify((string)($data['slug'] ?? preg_replace('/\.(md|markdown)$/i', '', (string)$names[$i])));
            $status = in_array($data['status'] ?? '', self::STATUSES, true) ? $data['status'] : 'draft';
            unset($data['slug'], $data['status']);
            $data['body'] = $data['body'] ?? $body;
            $e = $this->pod->entry($col, $slug);
            if ($e && $dup === 'skip') { $skipped++; continue; }
            if ($e) $this->pod->updateEntry($col, $slug, ['data' => $data, 'status' => $status]);
            else $this->pod->createEntry($col, $slug, $data, $status);
            $imported++;
        }
        $this->json(['type' => 'markdown', 'imported' => $imported, 'skipped' => $skipped]);
    }

    /** Frontmatter: key: value, "quoted", true/false, numbers, [a, b] and "- item" lists. */
    public static function frontmatter(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace("\r\n", "\n", $text));
        if (!preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $text, $m)) return [[], trim($text)];
        $data = []; $listKey = null;
        foreach (explode("\n", $m[1]) as $line) {
            if ($listKey !== null && preg_match('/^\s+-\s+(.*)$/', $line, $li)) { $data[$listKey][] = self::scalar($li[1]); continue; }
            $listKey = null;
            if (!preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/', $line, $kv)) continue;
            $v = trim($kv[2]);
            if ($v === '') { $data[$kv[1]] = []; $listKey = $kv[1]; continue; }
            if ($v[0] === '[' && str_ends_with($v, ']')) { $data[$kv[1]] = array_map([self::class, 'scalar'], array_filter(array_map('trim', explode(',', substr($v, 1, -1))), 'strlen')); continue; }
            $data[$kv[1]] = self::scalar($v);
        }
        return [$data, trim($m[2])];
    }

    private static function scalar(string $v): mixed
    {
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) return substr($v, 1, -1);
        if ($v === 'true') return true;
        if ($v === 'false') return false;
        if (is_numeric($v)) return $v + 0;
        return $v;
    }

    // ───────────────────────── forms (Posteingang) ─────────────────────────
    private const FORM_STATUSES = ['new', 'read', 'done', 'spam', 'confirmed', 'rejected'];

    /**
     * POST /api/form/{formId}: store a form submission. Not public as in Orbiter, but server-to-server only
     * (e.g. the website's own form handler) with the key `form_token` in the X-Lander-Token header.
     * Without a key set, intake is off.
     */
    private function formSubmit(string $formId): void
    {
        $token = (string)($this->cfg['form_token'] ?? '');
        if ($token === '' || !hash_equals($token, (string)($_SERVER['HTTP_X_LANDER_TOKEN'] ?? ''))) { $this->json(['error' => 'Not found'], 404); return; }
        $b = $this->body();
        if (!$b || count($b) > 50) { $this->json(['error' => 'Invalid body'], 400); return; }
        $data = [];
        foreach ($b as $k => $v) {
            if (!is_scalar($v) || strlen((string)$k) > 100 || $k === '_honeypot') continue;
            $data[(string)$k] = mb_substr((string)$v, 0, 5000);
        }
        if (!$data) { $this->json(['error' => 'Empty submission'], 400); return; }
        $id = Pod::uuid();
        $this->pod->run('INSERT INTO _forms (id, form_id, data, ip, created_at) VALUES (?,?,?,?,?)',
            [$id, $formId, json_encode($data, JSON_UNESCAPED_UNICODE), null, Pod::now()]);   // keine IP speichern (Datenschutz)
        $this->json(['ok' => true, 'id' => $id]);
    }

    /** Inbox for signed-in users: GET /api/forms, GET /api/forms/{formId}, PUT /api/forms/{id}/status, DELETE /api/forms/{id}. */
    private function forms(string $m, array $seg): void
    {
        $n = count($seg);
        if ($n === 0 && $m === 'GET') {
            $out = [];
            foreach ($this->pod->all('SELECT form_id, status, COUNT(*) AS count FROM _forms GROUP BY form_id, status') as $r) {
                $out[$r['form_id']] ??= ['new' => 0, 'read' => 0, 'done' => 0, 'spam' => 0, 'total' => 0];
                $out[$r['form_id']][$r['status']] = ($out[$r['form_id']][$r['status']] ?? 0) + (int)$r['count'];
                $out[$r['form_id']]['total'] += (int)$r['count'];
            }
            $this->json($out ?: new stdClass()); return;
        }
        if ($n === 1 && $m === 'GET') {
            $sql = 'SELECT * FROM _forms WHERE form_id = ?'; $a = [$seg[0]];
            if (!empty($_GET['status'])) { $sql .= ' AND status = ?'; $a[] = (string)$_GET['status']; }
            $limit = max(1, min((int)($_GET['limit'] ?? 50), 200)); $offset = max(0, (int)($_GET['offset'] ?? 0));
            $rows = $this->pod->all("$sql ORDER BY created_at DESC LIMIT $limit OFFSET $offset", $a);
            $this->json(array_map(fn($r) => ['data' => json_decode($r['data'], true) ?: new stdClass()] + $r, $rows)); return;
        }
        if ($n === 2 && $seg[1] === 'status' && $m === 'PUT') {
            $st = (string)($this->body()['status'] ?? '');
            if (!in_array($st, self::FORM_STATUSES, true)) { $this->json(['error' => 'Invalid status'], 400); return; }
            $this->pod->run('UPDATE _forms SET status = ? WHERE id = ?', [$st, $seg[0]]);
            $this->json(['ok' => true]); return;
        }
        if ($n === 1 && $m === 'DELETE') { $this->pod->run('DELETE FROM _forms WHERE id = ?', [$seg[0]]); $this->json(['ok' => true]); return; }
        $this->json(['error' => 'Not available in Lander'], 501);
    }

    // ───────────────────────── search ─────────────────────────
    private function recent(): void
    {
        $limit = max(1, min((int)($_GET['limit'] ?? 10), 50));
        $st = $_GET['status'] ?? null;
        $labels = array_column($this->pod->collections(), 'label', 'id');
        $rows = $st
            ? $this->pod->all("SELECT * FROM _entries WHERE status = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT $limit", [$st])
            : $this->pod->all("SELECT * FROM _entries WHERE deleted_at IS NULL ORDER BY updated_at DESC LIMIT $limit");
        $this->json(array_map(function ($r) use ($labels) {
            $d = json_decode($r['data'], true) ?: [];
            return ['collection' => $r['collection_id'], 'label' => $labels[$r['collection_id']] ?? $r['collection_id'], 'slug' => $r['slug'],
                'title' => $d['title'] ?? $r['slug'], 'status' => $r['status'], 'updated_at' => $r['updated_at']];
        }, $rows));
    }

    private function search(): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) { $this->json([]); return; }
        $rows = $this->pod->all("SELECT collection_id AS collection, slug, status, data FROM _entries WHERE deleted_at IS NULL AND (slug LIKE ? OR data LIKE ?) LIMIT 20", ["%$q%", "%$q%"]);
        $this->json(array_map(function ($r) { $d = json_decode($r['data'], true) ?: []; unset($r['data']); return $r + ['title' => $d['title'] ?? $r['slug']]; }, $rows));
    }

    // ───────────────────────── public read API ─────────────────────────
    /** GET /api/public/{collection}[/{slug}][?locale=] → published entries only, readable cross-origin by the configured sites. */
    private function publicApi(string $method, string $rest): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && in_array($origin, $this->cfg['public_origins'] ?? [], true)) { header("Access-Control-Allow-Origin: $origin"); header('Vary: Origin'); }
        if ($method === 'OPTIONS') { header('Access-Control-Allow-Methods: GET'); http_response_code(204); return; }
        if ($method !== 'GET') { $this->json(['error' => 'Method not allowed'], 405); return; }
        [$col, $slug] = array_pad(array_map('rawurldecode', explode('/', trim($rest, '/'), 2)), 2, null);
        $pod = new Pod($this->cfg['pod']);
        if ($col === 'media' && $slug) {   // GET /api/public/media/<id>?w=800&ar=16:9&fmt=webp : resized image for websites
            $m = $pod->one("SELECT * FROM _media WHERE id = ? AND mime_type LIKE 'image/%'", [$slug]);
            if (!$m) { $this->json(['error' => 'Not found'], 404); return; }
            if (Image::wanted() && Image::serve($m, rtrim($this->cfg['data_dir'], '/') . '/cache/media')) return;
            header('Location: ' . $m['url'], true, 302); return;
        }
        $c = $col ? $pod->collection($col) : null;
        if (!$c) { $this->json(['error' => 'Not found'], 404); return; }
        $locale = isset($_GET['locale']) ? (string)$_GET['locale'] : null;
        $now = Pod::now();
        $live = fn(array $e) => ($e['publish_at'] === null || $e['publish_at'] <= $now) && ($e['unpublish_at'] === null || $e['unpublish_at'] > $now);
        // Resolve image, file and media fields (stored as media id) for websites: {id, url, alt, type}
        $schema = json_decode($c['schema'] ?: '{}', true) ?: [];
        $mediaKeys = array_keys(array_filter($schema, fn($f) => in_array($f['type'] ?? '', ['image', 'file', 'media'], true)));
        $base = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        $resolve = function (mixed $v) use ($pod, $base) {
            if (!is_string($v) || $v === '') return $v;
            $m = $pod->one('SELECT id, url, alt, mime_type FROM _media WHERE id = ?', [$v]);
            if (!$m) return null;
            return ['id' => $m['id'], 'url' => str_starts_with((string)$m['url'], '/') ? $base . $m['url'] : $m['url'], 'alt' => $m['alt'], 'type' => $m['mime_type']];
        };
        $out = function (array $e) use ($mediaKeys, $resolve) {
            $d = $e['data'];
            foreach ($mediaKeys as $k) if (array_key_exists($k, $d)) $d[$k] = $resolve($d[$k]);
            return ['slug' => $e['slug'], 'locale' => $e['locale'], 'data' => $d ?: new stdClass(), 'updated_at' => $e['updated_at'], 'publish_at' => $e['publish_at']];
        };
        $ttl = max(0, (int)($this->cfg['public_cache_seconds'] ?? 10));   // how long browsers/proxies may reuse an answer; edits show up after at most this long
        header($ttl > 0 ? "Cache-Control: public, max-age=$ttl, stale-while-revalidate=" . ($ttl * 3) : 'Cache-Control: no-cache');
        if ($slug !== null && $slug !== '') {
            $e = $pod->entry($col, $slug, $locale ?? '');
            ($e && ($e['status'] === 'published' || ($e['status'] === 'scheduled' && $e['publish_at'] !== null)) && $live($e)) ? $this->json($out($e)) : $this->json(['error' => 'Not found'], 404);
            return;
        }
        // "Scheduled" goes live as soon as publish_at has passed (no background process needed)
        $list = array_values(array_filter(array_merge($pod->entries($col, 'published', $locale), array_filter($pod->entries($col, 'scheduled', $locale), fn($e) => $e['publish_at'] !== null)), $live));
        $this->json($c['singleton'] ? ($list ? $out($list[0]) : new stdClass()) : array_map($out, $list));
    }

    // ───────────────────────── utilities ─────────────────────────
    private function cookiePath(): string { return ($this->cfg['base_path'] ?? '') . '/'; }
    private function isAdmin(): bool { return ($this->user['role'] ?? '') === 'admin'; }
    private function https(): bool { return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; }

    private function sameOrigin(): bool
    {
        $o = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
        if ($o === '') return true;   // no browser context; the Strict cookie cannot ride along cross-site anyway
        $host = parse_url($o, PHP_URL_HOST) . (($p = parse_url($o, PHP_URL_PORT)) ? ":$p" : '');
        return strcasecmp($host, $_SERVER['HTTP_HOST'] ?? '') === 0;
    }

    private function slugify(string $s): string
    {
        $s = strtr(mb_strtolower(trim($s)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }

    private function body(): array
    {
        static $b = null;
        return $b ??= (json_decode((string)file_get_contents('php://input'), true) ?: []);
    }

    private function rateFile(string $k, string $ip): string { return rtrim($this->cfg['data_dir'], '/') . '/ratelimit-' . $k . '-' . hash('sha256', $ip) . '.json'; }
    private function rateOk(string $k, string $ip, int $max, int $win): bool
    {
        $r = json_decode((string)@file_get_contents($this->rateFile($k, $ip)), true);
        return !$r || $r['reset'] < time() || $r['count'] < $max;
    }
    private function rateHit(string $k, string $ip): void
    {
        $f = $this->rateFile($k, $ip); $r = json_decode((string)@file_get_contents($f), true);
        if (!$r || $r['reset'] < time()) $r = ['count' => 0, 'reset' => time() + 900];
        $r['count']++; file_put_contents($f, json_encode($r), LOCK_EX);
    }

    /** Output empty content fields as {}, not [] (PHP does not tell them apart, the UI does). */
    private static function objects(mixed $v): mixed
    {
        if (!is_array($v)) return $v;
        foreach ($v as $k => $x) $v[$k] = ($k === 'data' || $k === 'schema') && $x === [] ? new stdClass() : self::objects($x);
        return $v;
    }

    private function json(mixed $data, int $code = 200): void
    {
        $data = self::objects($data);
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
