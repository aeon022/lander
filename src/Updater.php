<?php
declare(strict_types=1);

/**
 * One-click update. Downloads the latest GitHub release ZIP plus its detached Ed25519 signature, verifies the
 * signature against the public key below, and only then replaces src/, bin/ and public/. Never touches
 * lander.config.php, data/ (the pod, backups, media cache), the root .htaccess or public/install.php.
 * Before it writes anything it saves the pod and the current code to data/update-rollback/.
 * Release signing: scripts/sign-release.php (private key stays with the maintainer, never in the repo).
 */
final class Updater
{
    /** Ed25519 public key (hex) of the release signature. */
    public const PUBLIC_KEY = '833eedf1c1f09c1ac27dd3d41fc1b67e1f0ff7e50a0a5fcde46207e8525d7aba';
    private const REPO = 'aeon022/lander';
    private const MAX_ZIP = 20 * 1024 * 1024;
    private const DIRS = ['src', 'bin', 'public'];

    /** What this server can do; the UI only offers the button when ok. */
    public static function capability(string $root): array
    {
        $why = [];
        if (!function_exists('sodium_crypto_sign_verify_detached')) $why[] = 'PHP sodium extension missing';
        if (!class_exists('ZipArchive')) $why[] = 'PHP zip extension missing';
        if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) $why[] = 'no outgoing HTTPS (curl or allow_url_fopen)';
        foreach (self::DIRS as $d) if (!is_writable("$root/$d")) { $why[] = "$d/ is not writable"; break; }
        return ['ok' => !$why, 'why' => $why];
    }

    /** @return array{version:string,files:int} */
    public static function apply(array $cfg, Pod $pod, string $root, string $currentVersion): array
    {
        @set_time_limit(300);
        $cap = self::capability($root);
        if (!$cap['ok']) throw new RuntimeException('Automatic update not possible: ' . implode(', ', $cap['why']) . '. Replace the files by hand.');
        $rel = json_decode((string)self::get('https://api.github.com/repos/' . self::REPO . '/releases/latest', 1024 * 1024), true);
        $ver = ltrim((string)($rel['tag_name'] ?? ''), 'vV');
        if (!preg_match('/^\d+(\.\d+){1,3}$/', $ver)) throw new RuntimeException('Release has no valid version');
        if (!version_compare($ver, $currentVersion, '>')) throw new RuntimeException('Already up to date');
        $zipUrl = $sigUrl = null;
        foreach ($rel['assets'] ?? [] as $a) {
            $u = (string)($a['browser_download_url'] ?? '');
            if (!str_starts_with($u, 'https://github.com/' . self::REPO . '/releases/download/')) continue;
            if (str_ends_with($u, "lander-$ver.zip")) $zipUrl = $u;
            if (str_ends_with($u, "lander-$ver.zip.sig")) $sigUrl = $u;
        }
        if (!$zipUrl || !$sigUrl) throw new RuntimeException('Release has no signed ZIP (lander-' . $ver . '.zip + .sig). Replace the files by hand.');
        $zipData = self::get($zipUrl, self::MAX_ZIP);
        $sig = sodium_hex2bin(trim(self::get($sigUrl, 1024)));
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, $zipData, sodium_hex2bin(self::PUBLIC_KEY))) {
            throw new RuntimeException('Signature check failed: the download was not signed by the Lander maintainer. Nothing was changed.');
        }

        $data = rtrim($cfg['data_dir'], '/');
        $tmpZip = "$data/update-" . bin2hex(random_bytes(6)) . '.zip';
        file_put_contents($tmpZip, $zipData);
        $z = new ZipArchive();
        try {
            if ($z->open($tmpZip) !== true) throw new RuntimeException('Downloaded ZIP is damaged');
            // a release made with a wrapper folder (lander-0.4.0/src/...) is accepted too
            $strip = '';
            $first = $z->getNameIndex(0) ?: '';
            if (!preg_match('~^(src|bin|public)/~', $first) && preg_match('~^([^/]+/)(src|bin|public)/~', (string)$z->getNameIndex($z->numFiles - 1), $m)) $strip = $m[1];
            $plan = [];
            for ($i = 0; $i < $z->numFiles; $i++) {
                $n = (string)$z->getNameIndex($i);
                if ($strip !== '' && str_starts_with($n, $strip)) $n = substr($n, strlen($strip));
                if ($n === '' || str_ends_with($n, '/')) continue;
                if (str_contains($n, '..') || str_contains($n, "\0") || $n[0] === '/' || str_contains($n, '\\')) throw new RuntimeException('Unsafe path in ZIP');
                if (!preg_match('~^(src|bin|public)/~', $n) || $n === 'public/install.php' || str_starts_with($n, 'public/media/') && !str_ends_with($n, '.htaccess')) continue;
                $plan[$i] = $n;
            }
            if (!isset(array_flip($plan)['src/Api.php'])) throw new RuntimeException('ZIP does not look like a Lander release');

            // rollback copy: pod + current code
            $rb = "$data/update-rollback";
            if (!is_dir($rb)) @mkdir($rb, 0700, true);
            Backup::snapshot($pod, $cfg['pod'], "$rb/pod-before-$ver.pod");
            $old = new ZipArchive();
            if ($old->open("$rb/code-$currentVersion.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach (self::DIRS as $d) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) {
                        $rel2 = $d . substr($f->getPathname(), strlen("$root/$d"));
                        if ($f->isFile() && !str_starts_with($rel2, 'public/media/')) $old->addFile($f->getPathname(), $rel2);
                    }
                }
                $old->close();
            }

            $count = 0;
            foreach ($plan as $i => $n) {
                $dest = "$root/$n";
                if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
                $body = $z->getFromIndex($i);
                if ($body === false) throw new RuntimeException("Cannot read $n from ZIP");
                $t = $dest . '.upd';
                file_put_contents($t, $body);
                rename($t, $dest);
                $count++;
            }
        } finally {
            @$z->close();
            @unlink($tmpZip);
        }
        // the new UI files carry unprefixed paths: put the install's base back in
        Rebase::run("$root/public", Rebase::current("$root/public"));
        if (function_exists('opcache_reset')) @opcache_reset();
        return ['version' => $ver, 'files' => $count];
    }

    private static function get(string $url, int $max): string
    {
        $hdr = ['User-Agent: Lander-Updater', 'Accept: application/octet-stream, application/vnd.github+json'];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER => $hdr, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
            $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        } else {
            $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'header' => implode("\r\n", $hdr), 'follow_location' => 1, 'max_redirects' => 4]]));
            $code = $r === false ? 0 : 200;
        }
        if ($r === false || $code !== 200) throw new RuntimeException('Download failed (' . $code . ')');
        if (strlen($r) > $max) throw new RuntimeException('Download too large');
        return $r;
    }
}
