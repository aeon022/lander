<?php
declare(strict_types=1);

/** TOTP (RFC 6238: HMAC-SHA1, 6 digits, 30 s), compatible with Google Authenticator, 1Password, Aegis; same format as Orbiter. */
final class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const STEP = 30;

    public static function secret(): string
    {
        $raw = random_bytes(20); $bits = $v = 0; $out = '';
        foreach (str_split($raw) as $c) {
            $v = ($v << 8) | ord($c); $bits += 8;
            while ($bits >= 5) { $out .= self::B32[($v >> ($bits - 5)) & 31]; $bits -= 5; }
            $v &= 0xFF;
        }
        return $out; // 20 bytes = 160 bits = exactly 32 base32 characters
    }

    private static function decode(string $s): string
    {
        $bits = $v = 0; $out = '';
        foreach (str_split(strtoupper(preg_replace('/[\s=-]/', '', $s))) as $ch) {
            $i = strpos(self::B32, $ch);
            if ($i === false) throw new InvalidArgumentException('invalid base32');
            $v = (($v << 5) | $i) & 0xFFFF; $bits += 5;
            if ($bits >= 8) { $out .= chr(($v >> ($bits - 8)) & 255); $bits -= 8; }
        }
        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $o = ord($h[19]) & 15;
        $n = ((ord($h[$o]) & 127) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Matching time step (±1 for clock drift) or null. Pass the last accepted step as $after to refuse replays. */
    public static function verify(string $secret, string $code, int $after = 0, ?int $now = null): ?int
    {
        $code = preg_replace('/\s/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return null;
        $t = intdiv($now ?? time(), self::STEP);
        foreach ([$t, $t - 1, $t + 1] as $step) {
            if ($step <= $after) continue;
            if (hash_equals(self::code($secret, $step), $code)) return $step;
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer = 'Lander'): string
    {
        return 'otpauth://totp/' . rawurlencode("$issuer:$account") . "?secret=$secret&issuer=" . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /** @return string[] shown once, stored as hashes */
    public static function recoveryCodes(int $n = 8): array
    {
        return array_map(function () { $h = bin2hex(random_bytes(5)); return substr($h, 0, 5) . '-' . substr($h, 5); }, range(1, $n));
    }

    public static function hashRecovery(string $c): string { return hash('sha256', strtolower(trim($c))); }
}
