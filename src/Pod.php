<?php
declare(strict_types=1);

/**
 * Pod: the .pod file (SQLite), same schema as Orbiter (packages/core/src/db.js), so a pod moves between
 * Orbiter (Node) and Lander (PHP). Media live as files in a folder (column `path`/`url`), not as BLOBs.
 * Password hashes differ: Lander writes PHP password_hash(); Orbiter's scrypt hashes need `bin/lander passwd`.
 */
final class Pod
{
    public PDO $db;

    public function __construct(string $path)
    {
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 3000;');
        $this->bootstrap();
        // the pod holds users and settings: owner only (a web server that ignores data/.htaccess then cannot serve it)
        if (is_file($path) && (fileperms($path) & 0077)) @chmod($path, 0600);
    }

    private function bootstrap(): void
    {
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS _meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS _digiid (address TEXT PRIMARY KEY, user_id TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')));
            CREATE TABLE IF NOT EXISTS _collections (
              id TEXT PRIMARY KEY, label TEXT NOT NULL, schema TEXT NOT NULL,
              singleton INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')));
            CREATE TABLE IF NOT EXISTS _entries (
              id TEXT PRIMARY KEY, collection_id TEXT NOT NULL REFERENCES _collections(id), slug TEXT NOT NULL,
              locale TEXT NOT NULL DEFAULT '', data TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft',
              sort_order INTEGER, deleted_at TEXT, publish_at TEXT, unpublish_at TEXT,
              created_at TEXT NOT NULL DEFAULT (datetime('now')), updated_at TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE(collection_id, slug, locale));
            CREATE TABLE IF NOT EXISTS _versions (
              id TEXT PRIMARY KEY, entry_id TEXT NOT NULL REFERENCES _entries(id), data TEXT NOT NULL,
              created_at TEXT NOT NULL DEFAULT (datetime('now')));
            CREATE TABLE IF NOT EXISTS _media (
              id TEXT PRIMARY KEY, filename TEXT NOT NULL, mime_type TEXT NOT NULL, size INTEGER NOT NULL, data BLOB,
              alt TEXT, folder TEXT NOT NULL DEFAULT '', url TEXT, path TEXT, focal_x REAL, focal_y REAL,
              created_at TEXT NOT NULL DEFAULT (datetime('now')));
            CREATE TABLE IF NOT EXISTS _users (
              id TEXT PRIMARY KEY, username TEXT NOT NULL UNIQUE, password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'editor',
              created_at TEXT NOT NULL DEFAULT (datetime('now')), last_login TEXT,
              totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0, totp_recovery TEXT, totp_last INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS _sessions (
              token TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES _users(id) ON DELETE CASCADE,
              expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')), ip TEXT, ua TEXT);
            CREATE TABLE IF NOT EXISTS _audit (
              id TEXT PRIMARY KEY, entry_id TEXT NOT NULL, username TEXT NOT NULL, action TEXT NOT NULL,
              created_at TEXT NOT NULL DEFAULT (datetime('now')));
            CREATE TABLE IF NOT EXISTS _forms (
              id TEXT PRIMARY KEY, form_id TEXT NOT NULL, data TEXT NOT NULL,
              status TEXT NOT NULL DEFAULT 'new', ip TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')));
            SQL);
        $this->db->exec("INSERT OR IGNORE INTO _meta (key, value) VALUES ('format_version', '1')");
    }

    // ── helpers ──
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function now(): string { return gmdate('Y-m-d H:i:s'); }

    public function all(string $sql, array $args = []): array
    {
        $s = $this->db->prepare($sql); $s->execute($args); return $s->fetchAll();
    }

    public function one(string $sql, array $args = []): ?array
    {
        $s = $this->db->prepare($sql); $s->execute($args); $r = $s->fetch(); return $r === false ? null : $r;
    }

    public function run(string $sql, array $args = []): int
    {
        $s = $this->db->prepare($sql); $s->execute($args); return $s->rowCount();
    }

    // ── meta ──
    public function getMeta(string $key): ?string { return $this->one('SELECT value FROM _meta WHERE key = ?', [$key])['value'] ?? null; }
    public function setMeta(string $key, string $value): void { $this->run('INSERT OR REPLACE INTO _meta (key, value) VALUES (?, ?)', [$key, $value]); }

    // ── collections ──
    public function collections(): array { return $this->all('SELECT * FROM _collections ORDER BY created_at, id'); }
    public function collection(string $id): ?array { return $this->one('SELECT * FROM _collections WHERE id = ?', [$id]); }

    // ── entries (data decoded) ──
    private static function decode(array $r): array { $r['data'] = json_decode($r['data'], true) ?? []; return $r; }

    public function entries(string $col, ?string $status = null, ?string $locale = null): array
    {
        if ($status === 'trash') {
            return array_map([self::class, 'decode'], $this->all('SELECT * FROM _entries WHERE collection_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC', [$col]));
        }
        $sql = 'SELECT * FROM _entries WHERE collection_id = ? AND deleted_at IS NULL'; $a = [$col];
        if ($status) { $sql .= ' AND status = ?'; $a[] = $status; }
        if ($locale !== null) { $sql .= ' AND locale = ?'; $a[] = $locale; }
        $sql .= ' ORDER BY COALESCE(sort_order,999999) ASC, updated_at DESC';
        return array_map([self::class, 'decode'], $this->all($sql, $a));
    }

    public function entry(string $col, string $slug, string $locale = ''): ?array
    {
        $r = $this->one('SELECT * FROM _entries WHERE collection_id = ? AND slug = ? AND locale = ? AND deleted_at IS NULL', [$col, $slug, $locale]);
        return $r ? self::decode($r) : null;
    }

    public function createEntry(string $col, string $slug, array $data, string $status = 'draft', string $locale = ''): string
    {
        $id = self::uuid(); $now = self::now();
        $this->run('INSERT INTO _entries (id, collection_id, slug, locale, data, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)',
            [$id, $col, $slug, $locale, json_encode((object)$data, JSON_UNESCAPED_UNICODE), $status, $now, $now]);
        return $id;
    }

    public function updateEntry(string $col, string $slug, array $b): bool
    {
        $e = $this->entry($col, $slug, (string)($b['locale'] ?? ''));
        if (!$e) return false;
        $this->run('INSERT INTO _versions (id, entry_id, data, created_at) VALUES (?,?,?,?)', [self::uuid(), $e['id'], json_encode((object)$e['data'], JSON_UNESCAPED_UNICODE), self::now()]);
        $this->run('DELETE FROM _versions WHERE entry_id = ? AND id NOT IN (SELECT id FROM _versions WHERE entry_id = ? ORDER BY created_at DESC LIMIT 20)', [$e['id'], $e['id']]);
        $this->run('UPDATE _entries SET slug = ?, data = ?, status = ?, publish_at = ?, unpublish_at = ?, updated_at = ? WHERE id = ?', [
            $b['slug'] ?? $slug,
            json_encode((object)($b['data'] ?? $e['data']), JSON_UNESCAPED_UNICODE),
            $b['status'] ?? $e['status'],
            array_key_exists('publish_at', $b) ? $b['publish_at'] : $e['publish_at'],
            array_key_exists('unpublish_at', $b) ? $b['unpublish_at'] : $e['unpublish_at'],
            self::now(), $e['id'],
        ]);
        return true;
    }

    public function audit(string $entryId, string $user, string $action): void
    {
        $this->run('INSERT INTO _audit (id, entry_id, username, action) VALUES (?,?,?,?)', [self::uuid(), $entryId, $user, $action]);
    }

    // ── users and sessions ──
    public function userByName(string $name): ?array { return $this->one('SELECT * FROM _users WHERE username = ?', [$name]); }

    public function sessionUser(string $token): ?array
    {
        if ($token === '') return null;
        return $this->one("SELECT u.id, u.username, u.role FROM _sessions s JOIN _users u ON s.user_id = u.id WHERE s.token = ? AND s.expires_at > datetime('now')", [$token]);
    }
}
