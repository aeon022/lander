<?php
// php scripts/totp-selftest.php : RFC 6238 test vectors (secret "12345678901234567890") + roundtrip
require __DIR__ . '/../src/Totp.php';
$s = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
assert(Totp::code($s, intdiv(59, 30)) === '287082');
assert(Totp::code($s, intdiv(1111111109, 30)) === '081804');
$x = Totp::secret(); assert(strlen($x) === 32 && preg_match('/^[A-Z2-7]+$/', $x));
$st = intdiv(1700000000, 30); assert(Totp::verify($x, Totp::code($x, $st), 0, 1700000000) === $st);
assert(Totp::verify($x, Totp::code($x, $st - 1), 0, 1700000000) === $st - 1);
assert(Totp::verify($x, Totp::code($x, $st + 3), 0, 1700000000) === null);
assert(Totp::verify($x, Totp::code($x, $st), $st, 1700000000) === null, 'replay');
assert(Totp::verify($x, 'abcdef') === null);
echo "totp ok\n";
