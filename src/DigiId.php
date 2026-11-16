<?php
declare(strict_types=1);

require_once __DIR__ . '/Secp256k1.php';

/**
 * Digi-ID (DigiByte wallet sign-in), same contract as Orbiter (see its src/digiid.js).
 * The wallet signs "digiid://<host>/api/digiid/callback?x=<nonce>" as a Bitcoin-style message with the magic
 * "DigiByte Signed Message:\n"; the address is a legacy P2PKH ("D…", version 30). Challenges live 5 minutes, one use,
 * as small files in data/ (PHP has no shared memory between requests).
 */
final class DigiId
{
    private const MAGIC = "DigiByte Signed Message:\n";
    private const B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private const TTL = 300;

    public static function available(): bool { return Secp256k1::available() && in_array('ripemd160', hash_algos(), true); }

    public static function base58check(string $payload): string
    {
        $data = $payload . substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        $n = self::toDec(bin2hex($data)); $out = '';
        while (bccomp($n, '0') > 0) { $out = self::B58[(int)bcmod($n, '58')] . $out; $n = bcdiv($n, '58', 0); }
        for ($i = 0; $i < strlen($data) && $data[$i] === "\0"; $i++) $out = '1' . $out;
        return $out;
    }
    private static function toDec(string $hex): string
    {
        if (function_exists('gmp_init')) return gmp_strval(gmp_init($hex, 16), 10);
        $n = '0'; foreach (str_split($hex) as $c) $n = bcadd(bcmul($n, '16'), (string)hexdec($c));
        return $n;
    }
    public static function addressOf(string $pubkey): string { return self::base58check("\x1e" . hash('ripemd160', hash('sha256', $pubkey, true), true)); }
    public static function looksLikeAddress(mixed $a): bool { return is_string($a) && preg_match('/^D[1-9A-HJ-NP-Za-km-z]{25,34}$/', $a) === 1; }

    private static function varint(int $n): string { return $n < 253 ? chr($n) : "\xfd" . pack('v', $n); }
    public static function messageHash(string $msg): string
    {
        return hash('sha256', hash('sha256', self::varint(strlen(self::MAGIC)) . self::MAGIC . self::varint(strlen($msg)) . $msg, true), true);
    }

    /** True when $sigB64 over $message was made by the key behind $address. Needs a compressed-key header (31–34) or uncompressed (27–30). */
    public static function verify(string $address, string $message, string $sigB64): bool
    {
        try {
            if (!self::looksLikeAddress($address)) return false;
            $raw = base64_decode($sigB64, true);
            if ($raw === false || strlen($raw) !== 65) return false;
            $h = ord($raw[0]);
            if ($h < 27 || $h > 34) return false;
            $pub = Secp256k1::recover(self::messageHash($message), substr($raw, 1), ($h - 27) & 3);
            if ($pub === null) return false;
            $compressed = $h >= 31;
            if (!$compressed) $pub = self::uncompress($pub);
            return hash_equals($address, self::addressOf($pub));
        } catch (Throwable) { return false; }
    }

    /** Uncompressed 65-byte form of a compressed key (needed for addresses made from uncompressed keys). */
    private static function uncompress(string $c): string
    {
        if (!function_exists('gmp_init')) throw new RuntimeException('uncompressed keys need GMP');
        $p = gmp_init('fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f', 16);
        $x = gmp_init(bin2hex(substr($c, 1)), 16);
        $y = gmp_powm(gmp_mod($x * $x * $x + 7, $p), gmp_div_q($p + 1, 4), $p);
        if (gmp_testbit($y, 0) !== (ord($c[0]) === 3)) $y = $p - $y;
        return "\x04" . substr($c, 1) . str_pad(hex2bin(str_pad(gmp_strval($y, 16), 64, '0', STR_PAD_LEFT)), 32, "\0", STR_PAD_LEFT);
    }

    // ── challenges ───────────────────────────────────────────────────────────────
    private static function file(string $dir, string $nonce): string { return rtrim($dir, '/') . '/digiid-' . $nonce . '.json'; }

    /** @return array{nonce:string,poll:string,uri:string} */
    public static function create(string $dir, string $purpose, ?string $userId, string $host, bool $secure, string $base = ''): array
    {
        foreach (glob(rtrim($dir, '/') . '/digiid-*.json') ?: [] as $f) if (filemtime($f) < time() - self::TTL) @unlink($f);
        $nonce = bin2hex(random_bytes(16)); $poll = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        file_put_contents(self::file($dir, $nonce), json_encode(['purpose' => $purpose, 'userId' => $userId, 'host' => $host, 'path' => $base . '/api/digiid/callback', 'secure' => $secure, 'poll' => $poll, 'status' => 'pending', 'address' => null, 'created' => time()]), LOCK_EX);
        return ['nonce' => $nonce, 'poll' => $poll, 'uri' => "digiid://$host$base/api/digiid/callback?x=$nonce" . ($secure ? '' : '&u=1')];
    }
    public static function load(string $dir, string $nonce): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) return null;
        $c = json_decode((string)@file_get_contents(self::file($dir, $nonce)), true);
        return is_array($c) && time() - (int)$c['created'] <= self::TTL ? $c : null;
    }
    public static function save(string $dir, string $nonce, array $c): void { file_put_contents(self::file($dir, $nonce), json_encode($c), LOCK_EX); }
    public static function drop(string $dir, string $nonce): void { if (preg_match('/^[a-f0-9]{32}$/', $nonce)) @unlink(self::file($dir, $nonce)); }

    /** Wallet callback body → ['ok'=>true,'address','challenge','nonce'] or ['error'=>…] */
    public static function check(string $dir, array $body): array
    {
        $address = $body['address'] ?? null; $uri = $body['uri'] ?? null; $sig = $body['signature'] ?? null;
        if (!is_string($uri) || strlen($uri) > 400 || !is_string($sig) || !is_string($address)) return ['error' => 'Bad request'];
        $u = parse_url(preg_replace('~^digiid:~', 'https:', $uri)); if (!$u || empty($u['host'])) return ['error' => 'Bad URI'];
        parse_str($u['query'] ?? '', $q); $nonce = (string)($q['x'] ?? '');
        $ch = self::load($dir, $nonce);
        if (!$ch || $ch['status'] !== 'pending') return ['error' => 'Unknown or expired challenge'];
        $host = $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        if ($host !== $ch['host'] || ($u['path'] ?? '') !== $ch['path']) return ['error' => 'URI does not belong to this site'];
        if ((($q['u'] ?? '') === '1') === (bool)$ch['secure']) return ['error' => 'Scheme mismatch'];
        if (!self::verify($address, $uri, $sig)) return ['error' => 'Invalid signature'];
        return ['ok' => true, 'address' => $address, 'challenge' => $ch, 'nonce' => $nonce];
    }
}
