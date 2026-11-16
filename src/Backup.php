<?php
declare(strict_types=1);

/**
 * Backup & restore of a Lander install: the pod (a plain SQLite file) and, optionally, the media folder as ZIP.
 * Backups never contain sessions. A restore validates the file first, keeps a copy of the current pod
 * (data/before-restore-*.pod, last 3) and rewrites media paths/URLs for the new host.
 */
final class Backup
{
    private const MEDIA_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'pdf'];
    private const NEED_TABLES = ['_meta', '_collections', '_entries', '_media', '_users'];

    /** Consistent copy of the live pod (VACUUM INTO; fallback: checkpoint + file copy), sessions removed. */
    public static function snapshot(Pod $pod, string $cfgPod, string $dest): void
    {
        @unlink($dest);
        try {
            $pod->db->exec('VACUUM INTO ' . $pod->db->quote($dest));
        } catch (Throwable) {
            $pod->db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            if (!copy($cfgPod, $dest)) throw new RuntimeException('Cannot copy pod');
        }
        $c = new PDO('sqlite:' . $dest, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $c->exec('DELETE FROM _sessions');
        $c = null;
        @chmod($dest, 0600); // password hashes and saved settings: owner only, even if a host ignores data/.htaccess
    }

    public static function download(array $cfg, Pod $pod, bool $withMedia): void
    {
        @set_time_limit(0);
        $dir = rtrim($cfg['data_dir'], '/');
        $tmp = "$dir/backup-" . bin2hex(random_bytes(6)) . '.pod';
        $stamp = gmdate('Y-m-d_Hi');
        self::snapshot($pod, $cfg['pod'], $tmp);
        $zip = null;
        try {
            if ($withMedia) {
                if (!class_exists('ZipArchive')) { @unlink($tmp); http_response_code(501); header('Content-Type: application/json'); echo json_encode(['error' => 'ZIP is not available on this server (PHP zip extension). Download the SQL file only.']); return; }
                $zip = "$dir/backup-" . bin2hex(random_bytes(6)) . '.zip';
                $z = new ZipArchive();
                if ($z->open($zip, ZipArchive::CREATE) !== true) throw new RuntimeException('Cannot create ZIP');
                $z->addFile($tmp, 'content.pod');
                $root = realpath($cfg['media_dir']);
                if ($root) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) {
                        if (!$f->isFile() || $f->isLink()) continue;
                        if (!in_array(strtolower($f->getExtension()), self::MEDIA_EXT, true)) continue;
                        $z->addFile($f->getPathname(), 'media/' . ltrim(substr($f->getPathname(), strlen($root)), '/'));
                    }
                }
                $z->close();
                $file = $zip; $name = "lander-backup-$stamp.zip"; $type = 'application/zip';
            } else {
                $file = $tmp; $name = "content-$stamp.pod"; $type = 'application/octet-stream';
            }
            header('Content-Type: ' . $type);
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: no-store');
            readfile($file);
        } finally {
            @unlink($tmp);
            if ($zip) @unlink($zip);
        }
    }

    /** @return array{ok:bool,error?:string,entries?:int,media_files?:int,warnings?:array,previous?:string} */
    public static function restore(array $cfg, Pod $pod, string $upload): array
    {
        @set_time_limit(0);
        $dir = rtrim($cfg['data_dir'], '/');
        $maxMb = (int)($cfg['max_restore_mb'] ?? 100);
        $head = (string)file_get_contents($upload, false, null, 0, 16);
        $tmp = "$dir/restore-" . bin2hex(random_bytes(6)) . '.pod';
        $zip = null;
        $warnings = [];
        try {
            if (str_starts_with($head, "SQLite format 3\0")) {
                if (!copy($upload, $tmp)) return self::fail('Cannot read upload');
            } elseif (str_starts_with($head, "PK\x03\x04")) {
                if (!class_exists('ZipArchive')) return self::fail('ZIP is not available on this server (PHP zip extension). Upload the SQL file (.pod) instead.');
                $zip = new ZipArchive();
                if ($zip->open($upload) !== true) return self::fail('Not a valid ZIP');
                $total = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) $total += (int)$zip->statIndex($i)['size'];
                if ($total > $maxMb * 4 * 1048576) return self::fail('Backup is too large to restore here');
                $in = $zip->getStream('content.pod');
                if (!$in) return self::fail('The ZIP has no content.pod');
                $out = fopen($tmp, 'wb'); stream_copy_to_stream($in, $out); fclose($out); fclose($in);
            } else {
                return self::fail('Not a Lander/Orbiter backup (expected a SQLite .pod file or a ZIP)');
            }

            // validate
            try {
                $c = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                if ($c->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') return self::fail('The file is damaged (integrity check failed)');
                $have = $c->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
                foreach (self::NEED_TABLES as $t) if (!in_array($t, $have, true)) return self::fail("Not a pod: table $t is missing");
                $admins = $c->query("SELECT password FROM _users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
                if (!$admins) return self::fail('The backup has no admin user: restoring it would lock you out');
                if (!array_filter($admins, fn($h) => str_starts_with((string)$h, '$'))) $warnings[] = 'No admin password in this backup is Lander-compatible (Orbiter hash). Set one with: php bin/lander passwd <name>';
                $blob = (int)$c->query('SELECT COUNT(*) FROM _media WHERE data IS NOT NULL AND (path IS NULL OR path = \'\')')->fetchColumn();
                if ($blob > 0) $warnings[] = "$blob media file(s) are stored inside the SQL file (Orbiter style) and are not served by Lander. Use: php bin/lander import-media";
                $entries = (int)$c->query('SELECT COUNT(*) FROM _entries WHERE deleted_at IS NULL')->fetchColumn();
                $c = null;
            } catch (Throwable) {
                return self::fail('The file is not a readable SQLite database');
            }

            // keep the current pod, then swap
            $prev = "$dir/before-restore-" . gmdate('Y-m-d_His') . '.pod';
            self::snapshot($pod, $cfg['pod'], $prev);
            foreach (array_slice(array_reverse(glob("$dir/before-restore-*.pod") ?: []), 3) as $old) @unlink($old);

            // media from ZIP (extension whitelist, no path tricks)
            $files = 0;
            if ($zip) {
                $root = rtrim($cfg['media_dir'], '/');
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = (string)$zip->getNameIndex($i);
                    if (!str_starts_with($n, 'media/') || str_ends_with($n, '/')) continue;
                    $rel = substr($n, 6);
                    if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0") || str_contains($rel, '\\') || $rel[0] === '/') continue;
                    if (!in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), self::MEDIA_EXT, true)) continue;
                    $dest = "$root/$rel";
                    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) continue;
                    $in = $zip->getStream($n); if (!$in) continue;
                    $out = fopen($dest, 'wb'); stream_copy_to_stream($in, $out); fclose($out); fclose($in);
                    $mt = (new finfo(FILEINFO_MIME_TYPE))->file($dest) ?: '';   // real type, like uploads
                    if (!in_array($mt, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'application/pdf'], true)) { @unlink($dest); continue; }
                    chmod($dest, 0644); $files++;
                }
                $zip->close(); $zip = null;
            }

            try { $pod->db->exec('PRAGMA journal_mode = DELETE'); } catch (Throwable) {}
            if (!@rename($tmp, $cfg['pod'])) { if (!copy($tmp, $cfg['pod'])) return self::fail('Cannot replace the pod (permissions?). Your previous pod is untouched.'); @unlink($tmp); }
            @unlink($cfg['pod'] . '-wal'); @unlink($cfg['pod'] . '-shm');

            // media paths/URLs belong to the host: rewrite for this one
            $n = new PDO('sqlite:' . $cfg['pod'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $n->exec('PRAGMA journal_mode = WAL');
            $up = $n->prepare('UPDATE _media SET path = ?, url = ? WHERE id = ?');
            foreach ($n->query("SELECT id, filename, folder FROM _media WHERE path IS NOT NULL AND path != ''")->fetchAll() as $m) {
                $rel = ($m['folder'] !== '' ? $m['folder'] . '/' : '') . $m['filename'];
                $up->execute([rtrim($cfg['media_dir'], '/') . '/' . $rel, rtrim($cfg['media_url'], '/') . '/' . $rel, $m['id']]);
            }
            $n = null;
            return ['ok' => true, 'entries' => $entries, 'media_files' => $files, 'warnings' => $warnings, 'previous' => basename($prev)];
        } finally {
            if (is_file($tmp)) @unlink($tmp);
            if ($zip instanceof ZipArchive) @$zip->close();
        }
    }

    /** Write a backup to $dest (a .pod, or a .zip with media). Used by the CLI and the automatic backup. */
    public static function make(array $cfg, Pod $pod, bool $withMedia, string $dest): void
    {
        @set_time_limit(0);
        $tmp = $dest . '.tmp';
        self::snapshot($pod, $cfg['pod'], $tmp);
        if (!$withMedia) { rename($tmp, $dest); return; }
        if (!class_exists('ZipArchive')) { @unlink($tmp); throw new RuntimeException('PHP zip extension missing'); }
        $z = new ZipArchive();
        if ($z->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { @unlink($tmp); throw new RuntimeException('Cannot create ZIP'); }
        $z->addFile($tmp, 'content.pod');
        $root = realpath($cfg['media_dir']);
        if ($root) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && !$f->isLink() && in_array(strtolower($f->getExtension()), self::MEDIA_EXT, true)) $z->addFile($f->getPathname(), 'media/' . ltrim(substr($f->getPathname(), strlen($root)), '/'));
        }
        $z->close();
        @unlink($tmp);
    }

    /** Keep only the newest $keep files matching $glob. */
    public static function rotate(string $glob, int $keep): void
    {
        $all = glob($glob) ?: []; rsort($all);
        foreach (array_slice($all, max(1, $keep)) as $old) @unlink($old);
    }

    /**
     * Backup without cron: called after a login; writes data/backups/auto-<date>.pod when the newest one is older than
     * `auto_backup_days` (default 7, 0 = off) and keeps `auto_backup_keep` (default 8). Never throws.
     */
    public static function auto(array $cfg, Pod $pod): void
    {
        try {
            $days = (int)($cfg['auto_backup_days'] ?? 7);
            if ($days <= 0) return;
            $dir = rtrim($cfg['data_dir'], '/') . '/backups';
            $newest = 0;
            foreach (glob("$dir/auto-*.pod") ?: [] as $f) $newest = max($newest, (int)filemtime($f));
            if (time() - $newest < $days * 86400) return;
            if (!is_dir($dir) && !mkdir($dir, 0700, true)) return;
            self::make($cfg, $pod, false, "$dir/auto-" . gmdate('Y-m-d_His') . '.pod');
            self::rotate("$dir/auto-*.pod", (int)($cfg['auto_backup_keep'] ?? 8));
        } catch (Throwable $e) { error_log('[lander] auto backup: ' . $e->getMessage()); }
    }

    private static function fail(string $msg): array { return ['ok' => false, 'error' => $msg]; }
}
