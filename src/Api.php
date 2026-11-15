<?php
declare(strict_types=1);

require_once __DIR__ . '/Pod.php';
require_once __DIR__ . '/Backup.php';

/**
 * Lander API: the subset of Orbiter's admin API (packages/admin/src/routes) the Station UI needs,
 * plus a public read API for websites (/api/public/...) and Markdown import.
 * Same paths, same JSON shapes as Orbiter, so the Orbiter admin pages run unchanged.
 */
final class Api
{
    private Pod $pod;
    private ?array $user = null;
    private const STATUSES = ['draft', 'in_review', 'published', 'scheduled'];
    private const META_KEYS = [
        'site.name', 'site.url', 'site.description', 'site.locale', 'site.locales',
        'media.img_max_width', 'media.img_quality', 'media.img_convert_webp',
        'dashboard.notes', 'dashboard.todos', 'dashboard.show_calendar', 'dashboard.show_recent',
        'dashboard.show_collections', 'dashboard.show_workspace', 'ui.theme', 'workflow.review',
        'format_version', 'nav.hidden', 'nav.groups',
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
            $r === 'backup' && $m === 'GET' => $this->backup(),
            $r === 'restore' && $m === 'POST' => $this->restore(),
            $r === 'users' && $n === 1 && $m === 'GET' => $this->json($this->isAdmin() ? $this->pod->all('SELECT id, username, role, created_at, last_login, totp_enabled FROM _users ORDER BY created_at') : []),
            $r === 'account' && ($seg[1] ?? '') === 'password' => $this->changePassword(),
            $r === 'account' && ($seg[1] ?? '') === 'username' => $this->changeUsername(),
            $r === 'account' && ($seg[1] ?? '') === 'sessions' => $this->sessions($m, $seg[2] ?? null),
            $r === 'account' && ($seg[1] ?? '') === '2fa' => $this->json(['enabled' => false, 'recoveryLeft' => 0]),
            $r === 'api-keys' => $this->json(['keys' => []]),
            $r === 'webhooks' => $this->json(['hooks' => [], 'log' => []]),
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
        if (!empty($u['totp_enabled'])) { $this->json(['error' => 'Two-factor login is not available in Lander yet'], 401); return; }

        $token = bin2hex(random_bytes(32));
        $this->pod->run("DELETE FROM _sessions WHERE expires_at < datetime('now')");
        $this->pod->run('INSERT INTO _sessions (token, user_id, expires_at, ip, ua) VALUES (?,?,?,?,?)',
            [$token, $u['id'], gmdate('Y-m-d H:i:s', time() + 30 * 86400), $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)]);
        $this->pod->run("UPDATE _users SET last_login = datetime('now') WHERE id = ?", [$u['id']]);
        if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) $this->pod->run('UPDATE _users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
        setcookie('orb_sess', $token, ['expires' => time() + 30 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $this->https()]);
        $this->json(['ok' => true, 'user' => ['id' => $u['id'], 'username' => $u['username'], 'role' => $u['role']]]);
    }

    private function logout(): void
    {
        $t = $_COOKIE['orb_sess'] ?? '';
        if ($t !== '') $this->pod->run('DELETE FROM _sessions WHERE token = ?', [$t]);
        setcookie('orb_sess', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $this->https()]);
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

    /** Eigene Sitzungen anzeigen und andere Geräte abmelden (wie Orbiter: id = Hash des Tokens, nie das Token selbst). */
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
            'adminVersion' => 'Lander 0.1.0 · Oberfläche Orbiter 0.3.90',
            'collections' => $cols,
            'nav' => ['hidden' => array_values(array_filter(array_map('trim', explode(',', $hidden)))), 'groups' => json_decode($this->pod->getMeta('nav.groups') ?? 'null', true)],
        ]);
    }

    private function metaAllowed(string $k): bool { return in_array($k, self::META_KEYS, true) || preg_match('/^(preview_url|templates)\.[a-z0-9_-]+$/', $k) === 1; }
    private function metaAll(): void { $o = []; foreach (self::META_KEYS as $k) $o[$k] = $this->pod->getMeta($k); $this->json($o); }
    private function metaGet(string $k): void { $k = str_replace('~', '.', $k); $this->metaAllowed($k) ? $this->json(['key' => $k, 'value' => $this->pod->getMeta($k)]) : $this->json(['error' => 'Key not allowed'], 403); }
    private const META_ADMIN_ONLY = ['site.url', 'workflow.review', 'format_version'];
    /** Non-admins must not change site-wide settings; preview URLs are admin-only and may only be http(s) or site-relative (they end up in an <a href>). */
    private function metaWritable(string $k, mixed $v): bool
    {
        $preview = str_starts_with($k, 'preview_url.');
        if (!$this->isAdmin() && ($preview || in_array($k, self::META_ADMIN_ONLY, true))) return false;
        if ($preview && $v !== null && $v !== '' && !preg_match('#^(https?://|/(?!/))#i', trim((string)$v))) return false;
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
            $this->pod->audit($after['id'], $who, ($b['status'] ?? null) === 'published' && ($before['status'] ?? '') !== 'published' ? 'publish' : 'update');
            $this->json($after); return;
        }
        if ($sub === null && $m === 'DELETE') {
            $e = $this->pod->entry($col, $slug, $locale);
            if (!$e) { $this->json(['error' => 'Not found'], 404); return; }
            $this->pod->run('UPDATE _entries SET deleted_at = ? WHERE id = ?', [Pod::now(), $e['id']]);
            $this->pod->audit($e['id'], $who, 'delete');
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
        if (($seg[1] ?? '') === 'raw') { header('Location: ' . $item['url'], true, 302); return; }
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
     * POST /api/form/{formId}: Formular-Einsendung speichern. Nicht öffentlich wie bei Orbiter, sondern nur
     * Server-zu-Server (z. B. anfrage.php der Website) mit Schlüssel `form_token` im Header X-Lander-Token.
     * Ohne gesetzten Schlüssel ist die Annahme aus.
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

    /** Posteingang für angemeldete Nutzer: GET /api/forms, GET /api/forms/{formId}, PUT /api/forms/{id}/status, DELETE /api/forms/{id}. */
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
        $c = $col ? $pod->collection($col) : null;
        if (!$c) { $this->json(['error' => 'Not found'], 404); return; }
        $locale = isset($_GET['locale']) ? (string)$_GET['locale'] : null;
        $now = Pod::now();
        $live = fn(array $e) => ($e['publish_at'] === null || $e['publish_at'] <= $now) && ($e['unpublish_at'] === null || $e['unpublish_at'] > $now);
        // Bild-, Datei- und Medienfelder (gespeichert als Medien-ID) für Websites auflösen: {id, url, alt, type}
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
        header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
        if ($slug !== null && $slug !== '') {
            $e = $pod->entry($col, $slug, $locale ?? '');
            ($e && ($e['status'] === 'published' || ($e['status'] === 'scheduled' && $e['publish_at'] !== null)) && $live($e)) ? $this->json($out($e)) : $this->json(['error' => 'Not found'], 404);
            return;
        }
        // «Geplant» wird live, sobald publish_at erreicht ist (kein Hintergrundprozess nötig)
        $list = array_values(array_filter(array_merge($pod->entries($col, 'published', $locale), array_filter($pod->entries($col, 'scheduled', $locale), fn($e) => $e['publish_at'] !== null)), $live));
        $this->json($c['singleton'] ? ($list ? $out($list[0]) : new stdClass()) : array_map($out, $list));
    }

    // ───────────────────────── utilities ─────────────────────────
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

    /** Leere Inhaltsfelder als {} ausgeben, nicht als [] (PHP kennt keinen Unterschied, die Oberfläche schon). */
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
