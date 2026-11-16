# Security Policy

## Reporting a vulnerability

Please report security vulnerabilities privately, not as a public GitHub issue.

**Preferred: [GitHub Security Advisories](https://github.com/aeon022/lander/security/advisories/new)**
("Security" tab → "Report a vulnerability"). This opens a private draft advisory visible only to the
maintainer, with a comment thread for coordination and a CVE request once a fixed version is out.

Include, if you have them:
- The Lander version (Settings → Pod shows it) and your PHP version / host type
- Steps to reproduce, ideally against a throwaway install
- Impact: what an attacker can actually do with it

## What's in scope

The PHP code in `src/`, `public/` (admin UI, installer) and `bin/`, the signed update mechanism, and the release ZIPs.
Findings that only apply to a misconfigured host (for example a web server that serves `data/` although `data/.htaccess`
asks it not to) are welcome as hardening reports.

## Release signing

Every release ZIP is signed with an Ed25519 key (`lander-X.Y.Z.zip.sig`); the public key is in `src/Updater.php`.
If you ever see an update that is not signed by that key, do not install it and report it here.

## Supported versions

Only the latest release is supported. Use **Update now** in the admin or unpack the latest release over your install
(keep `lander.config.php`, `data/` and `public/media/`).
