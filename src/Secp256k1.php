<?php
declare(strict_types=1);

/**
 * Just enough secp256k1 for Digi-ID: recover the public key from a compact signature (the same operation
 * as Bitcoin's "verifymessage"). Pure PHP on top of GMP, or bcmath when GMP is missing. Not constant time;
 * it only handles public data (signature, hash), never a secret key.
 */
final class Secp256k1
{
    private const P = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';
    private const N = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
    private const GX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    private const GY = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';

    public static function available(): bool { return function_exists('gmp_init') || function_exists('bcadd'); }

    // ── numbers: GMP objects, or decimal strings with bcmath ─────────────────────────
    public static bool $noGmp = false; // tests: force the bcmath path
    private static function gmp(): bool { return !self::$noGmp && function_exists('gmp_init'); }
    private static function hex(string $h): mixed
    {
        if (self::gmp()) return gmp_init($h, 16);
        $n = '0'; foreach (str_split($h) as $c) $n = bcadd(bcmul($n, '16'), (string)hexdec($c));
        return $n;
    }
    private static function add(mixed $a, mixed $b): mixed { return self::gmp() ? $a + $b : bcadd($a, $b); }
    private static function sub(mixed $a, mixed $b): mixed { return self::gmp() ? $a - $b : bcsub($a, $b); }
    private static function mul(mixed $a, mixed $b): mixed { return self::gmp() ? $a * $b : bcmul($a, $b); }
    private static function mod(mixed $a, mixed $m): mixed
    {
        if (self::gmp()) return gmp_mod($a, $m);
        $r = bcmod($a, $m); return $r[0] === '-' ? bcadd($r, $m) : $r;
    }
    private static function powm(mixed $b, mixed $e, mixed $m): mixed { return self::gmp() ? gmp_powm($b, $e, $m) : bcpowmod($b, $e, $m); }
    private static function cmp(mixed $a, mixed $b): int { return self::gmp() ? gmp_cmp($a, $b) : bccomp($a, $b); }
    private static function zero(mixed $a): bool { return self::cmp($a, self::hex('0')) === 0; }
    private static function odd(mixed $a): bool { return self::gmp() ? gmp_testbit($a, 0) : bcmod($a, '2') === '1'; }
    private static function bytes(mixed $a): string
    {
        if (self::gmp()) return str_pad(hex2bin(str_pad(gmp_strval($a, 16), 64, '0', STR_PAD_LEFT)), 32, "\0", STR_PAD_LEFT);
        $h = ''; while (bccomp($a, '0') > 0) { $h = dechex((int)bcmod($a, '16')) . $h; $a = bcdiv($a, '16', 0); }
        return hex2bin(str_pad($h, 64, '0', STR_PAD_LEFT));
    }
    private static function inv(mixed $a, mixed $p): mixed { return self::powm($a, self::sub($p, self::hex('2')), $p); }

    // ── Jacobian points (X, Y, Z); null = infinity ────────────────────────────────
    private static function dbl(?array $p, mixed $P): ?array
    {
        if ($p === null || self::zero($p[1])) return null;
        [$x, $y, $z] = $p;
        $m = fn($a, $b) => self::mod(self::mul($a, $b), $P);
        $yy = $m($y, $y); $s = $m(self::hex('4'), $m($x, $yy)); $mm = $m(self::hex('3'), $m($x, $x));
        $x3 = self::mod(self::sub($m($mm, $mm), self::mul(self::hex('2'), $s)), $P);
        $y3 = self::mod(self::sub($m($mm, self::sub($s, $x3)), $m(self::hex('8'), $m($yy, $yy))), $P);
        return [$x3, $y3, $m(self::hex('2'), $m($y, $z))];
    }
    private static function addp(?array $a, ?array $b, mixed $P): ?array
    {
        if ($a === null) return $b; if ($b === null) return $a;
        $m = fn($u, $v) => self::mod(self::mul($u, $v), $P);
        [$x1, $y1, $z1] = $a; [$x2, $y2, $z2] = $b;
        $z1z1 = $m($z1, $z1); $z2z2 = $m($z2, $z2);
        $u1 = $m($x1, $z2z2); $u2 = $m($x2, $z1z1);
        $s1 = $m($y1, $m($z2, $z2z2)); $s2 = $m($y2, $m($z1, $z1z1));
        if (self::cmp($u1, $u2) === 0) return self::cmp($s1, $s2) === 0 ? self::dbl($a, $P) : null;
        $h = self::mod(self::sub($u2, $u1), $P); $r = self::mod(self::sub($s2, $s1), $P);
        $hh = $m($h, $h); $hhh = $m($h, $hh); $v = $m($u1, $hh);
        $x3 = self::mod(self::sub(self::sub($m($r, $r), $hhh), self::mul(self::hex('2'), $v)), $P);
        $y3 = self::mod(self::sub($m($r, self::sub($v, $x3)), $m($s1, $hhh)), $P);
        return [$x3, $y3, $m($h, $m($z1, $z2))];
    }
    private static function scalar(array $p, mixed $k, mixed $P): ?array
    {
        $bits = self::gmp() ? gmp_strval($k, 2) : self::binary($k);
        $acc = null; $pt = [$p[0], $p[1], self::hex('1')];
        foreach (str_split(strrev($bits)) as $b) { if ($b === '1') $acc = self::addp($acc, $pt, $P); $pt = self::dbl($pt, $P); }
        return $acc;
    }
    private static function binary(string $dec): string { $o = ''; while (bccomp($dec, '0') > 0) { $o = bcmod($dec, '2') . $o; $dec = bcdiv($dec, '2', 0); } return $o ?: '0'; }
    private static function affine(?array $p, mixed $P): ?array
    {
        if ($p === null) return null;
        $zi = self::inv($p[2], $P); $z2 = self::mod(self::mul($zi, $zi), $P);
        return [self::mod(self::mul($p[0], $z2), $P), self::mod(self::mul($p[1], self::mod(self::mul($z2, $zi), $P)), $P)];
    }

    /**
     * Public key (33 bytes, compressed) that signed $hash32, from the 64-byte compact signature (r||s) and recovery id 0/1.
     * Returns null when the signature is not valid.
     */
    public static function recover(string $hash32, string $sig64, int $recid): ?string
    {
        if (!self::available() || strlen($hash32) !== 32 || strlen($sig64) !== 64 || $recid < 0 || $recid > 1) return null;
        $P = self::hex(self::P); $N = self::hex(self::N); $one = self::hex('1');
        $r = self::hex(bin2hex(substr($sig64, 0, 32))); $s = self::hex(bin2hex(substr($sig64, 32)));
        if (self::zero($r) || self::zero($s) || self::cmp($r, $N) >= 0 || self::cmp($s, $N) >= 0) return null;
        // R = (r, y) with y² = r³ + 7 (p ≡ 3 mod 4, so the root is v^((p+1)/4))
        $v = self::mod(self::add(self::mul($r, self::mul($r, $r)), self::hex('7')), $P);
        $y = self::powm($v, self::hex('3fffffffffffffffffffffffffffffffffffffffffffffffffffffffbfffff0c'), $P);
        if (self::cmp(self::mod(self::mul($y, $y), $P), $v) !== 0) return null;
        if (self::odd($y) !== ($recid === 1)) $y = self::sub($P, $y);
        $e = self::mod(self::hex(bin2hex($hash32)), $N);
        $ri = self::inv($r, $N);
        $u1 = self::mod(self::mul(self::sub($N, $e), $ri), $N);   // -e/r
        $u2 = self::mod(self::mul($s, $ri), $N);                  // s/r
        $G = [self::hex(self::GX), self::hex(self::GY)];
        $q = self::addp(self::scalar($G, $u1, $P), self::scalar([$r, $y], $u2, $P), $P);
        $a = self::affine($q, $P);
        if ($a === null) return null;
        return (self::odd($a[1]) ? "\x03" : "\x02") . self::bytes($a[0]);
    }
}
