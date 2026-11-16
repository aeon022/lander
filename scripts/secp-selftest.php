<?php
// php scripts/secp-selftest.php <vectors.json>   vectors: [{hash, sig, recid, pub}] hex, made with @noble/secp256k1
require __DIR__ . '/../src/Secp256k1.php';
if (getenv('NOGMP')) Secp256k1::$noGmp = true;
$v = json_decode(file_get_contents($argv[1]), true); $bad = 0;
$t = microtime(true);
foreach ($v as $i => $x) {
    $got = Secp256k1::recover(hex2bin($x['hash']), hex2bin($x['sig']), $x['recid']);
    if ($got === null || bin2hex($got) !== $x['pub']) { $bad++; echo "FAIL #$i\n"; }
}
// tampered hash must not give the same key
$x = $v[0]; $h = hex2bin($x['hash']); $h[0] = chr(ord($h[0]) ^ 1);
if ((Secp256k1::recover($h, hex2bin($x['sig']), $x['recid']) ?? '') === hex2bin($x['pub'])) { $bad++; echo "tamper accepted\n"; }
printf("%s: %d vectors, %d failed, %.0f ms each (%s)\n", $bad ? 'FAIL' : 'ok', count($v), $bad, (microtime(true) - $t) / max(1, count($v)) * 1000, getenv('NOGMP') || !function_exists('gmp_init') ? 'bcmath' : 'gmp');
exit($bad ? 1 : 0);
