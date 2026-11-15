<?php
// php scripts/sign-release.php lander-X.Y.Z.zip [path/to/release.key]  → writes lander-X.Y.Z.zip.sig (hex, detached Ed25519)
// The private key lives outside the repo (default ~/.lander-signing/release.key). Public key: Updater::PUBLIC_KEY.
$zip = $argv[1] ?? die("usage: php sign-release.php <zip> [keyfile]\n");
$key = $argv[2] ?? (getenv('HOME') . '/.lander-signing/release.key');
$sk = sodium_hex2bin(trim((string)file_get_contents($key)));
file_put_contents("$zip.sig", sodium_bin2hex(sodium_crypto_sign_detached((string)file_get_contents($zip), $sk)) . "\n");
echo "signed: $zip.sig\n";
