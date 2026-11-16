# stage.md — Lander

## Stand (2026-11-13)
- Gestartet aus Orbiter-Admin (Station-Modus Standard). PHP-API: Login/Logout/Me, Info, Meta, Sammlungen (CRUD, Singleton), Einträge (CRUD, Status, Papierkorb, Versionen, Duplizieren, Sortieren, Bulk), Medien als Dateien (Upload, Alt-Text, Löschen), Markdown-Import (UI + CLI), Suche, öffentliche Lese-API mit CORS.
- Lokal getestet (Docker PHP 8.3) mit curl: Login, falsches Passwort, CSRF, Veröffentlichen, öffentliche API zeigt nur Veröffentlichtes, Bild-Upload, getarnte PHP-Datei abgelehnt, Markdown-Import, Pod nicht abrufbar.
- **Im Browser getestet (13.11., eingeloggt, Headless-Chrome, Netzwerk + Konsole):** Dashboard, Einträge, Editor (auch Singleton), Medien, Sammlungen, Schema, Import, Einstellungen, Konto: keine 4xx/5xx, keine JS-Fehler. Dock mit Sammlungen sichtbar.
- Behoben: Dock fehlte (sidebar.js lud xfce.js nur bei gespeichertem orb_style; `/api/info` ohne `podPath` brach das Dock ab).
- Noch sichtbar, aber ohne Funktion: KI-Knöpfe im Editor («AI», «Suggest»), Bereiche Webhooks/API-Schlüssel/Vorlagen in den Einstellungen (leer).

- 13.11.: Pinas Medien importiert (44 Dateien: pina, team, blumen, logos, lehrgang, dokumente). Sammlungen für psychiaterin-graz.at: `hinweis` (Singleton), `termine`, `blog` mit Schemas. Lese-API löst Medienfelder auf, geplante Einträge werden zum Zeitpunkt live. Website angebunden und getestet.

- 13.11.: Branding: Zeichen (Planet mit Landebeinen), Favicon, Login mit Aufsetz-Animation und Seitenname (`/api/brand`, öffentlich nur der Name), Statusleiste «ORBITER › LANDER», Titel «Lander — …», Touchdown-Amber als Akzent der Space-Palette (`html[data-lander]`), Version «Lander 0.1.0 · Oberfläche Orbiter 0.3.90». Seitenname in den Einstellungen gesetzt.

## Stand (2026-11-14, Nachzug aus Orbiter 0.3.91+, uncommittet)
- Oberfläche per 3-Wege-Merge (Orbiter damals → Lander → Orbiter heute) aktualisiert: 10 Themes (Space, missionctl, Zen, Catppuccin, Terminal, Paper, Nord, Gruvbox, Dracula, Tokyo Night), Schrift-Auswahl (IBM Plex), eigene Farben, Responsive für Classic/Glass (untere Tab-Leiste ohne Calendar-Tab, `data-lander`), Station-Mobile-Fix. Landers Blöcke («Lander: …», Touchdown-Amber, Branding, Ausblenden) unverändert erhalten.
- Sicherheit (aus Orbiter-Audit): `window.orbEsc` + Escapes in dashboard/collections/editor/entries/settings; `PUT /api/meta`: `site.url`, `workflow.review`, `format_version`, `preview_url.*` nur Admin, `preview_url` nur `http(s)://` oder `/…` (`metaWritable` in `Api.php`); Request-Größenlimit (`max_upload_mb`+1 MB → 413); `display_errors` aus. Username/Slug waren schon sicher (Regex bzw. `slugify`).
- Getestet: Docker (php:8.3-cli, Scratch-Kopie, leerer Pod) per curl (Editor-Rechte, Preview-URL, 413, Slug, Username) und Headless-Chrome-Screenshots (Station Handy, Glass Handy, Einstellungen Desktop). Nicht getestet: Apache/.htaccess-Pfad, echter Pod mit Pinas Daten, Klick-Tests.

## Als Nächstes
1. KI-Knöpfe und leere Einstellungsbereiche ausblenden; Klick-Tests (Speichern, Upload über Oberfläche).
2. psychiaterin-graz.at anbinden: Hinweis + Lehrgangstermine live aus `/api/public/…`.
3. Benutzerverwaltung im Admin, 2FA (TOTP), optional Bildverkleinerung (GD).
4. Deployment-Anleitung ipax (Subdomain `redaktion.`).

## 2026-11-15 — Settings-Layout aus Orbiter (ba10dbd3)
- `public/settings.html`: Sektionen (You/General/Content/Publishing/System), ausgeglichene Spalten, einklappbare Karten, Sprungleiste. Patch 1:1 aus Orbiter übernommen.
- Getestet im Docker-Scratch (PHP 8.3/Apache, Port 8097; 8088 belegt durch den echten lander-Container): 15 Karten, keine Konsolenfehler, kein Überlauf (xfce/glass/classic 1440, xfce 390). Speichern nicht separat getestet (Logik unverändert).

## 2026-11-15 — Backup/Restore
- `src/Backup.php`, Routen `GET /api/backup[?media=1]`, `POST /api/restore` (Admin). Settings → Pod: Download SQL-Datei / ZIP, Restore mit Bestätigung.
- Getestet (Docker, PHP 8.3): SQL- und ZIP-Backup, Restore (SQL und ZIP, 45 Medien), kaputte/fremde Dateien (422), ZIP mit `..`-Pfaden und `.php`/Fake-PNG (abgewiesen), Sitzungen nach Restore ungültig, UI-Durchlauf (Upload → Meldung → Login). Nicht getestet: Editor-Rolle (403 per Code-Review), echter Shared Host (PHP-Limits, Zeitlimit bei großen ZIPs), Orbiter-Pod mit BLOB-Medien.
- `lander.js` blendet leere Settings-Abschnitte samt Chip aus (Publishing).

## 2026-11-15 — Unterordner, Installer, Auto-Backup, Bildgrößen (0.2.0)
- `src/Rebase.php` + `bin/lander rebase`: schreibt absolute UI-Pfade (/api/, /*.html|js|css|svg) auf `base_path` um, idempotent (`public/.base`). `index.php` strippt den Basispfad, Cookie-Pfad = Basis. Root-`.htaccess` leitet im Unterordner alles nach `public/` (nur dort erreichbar).
- `public/install.php`: erkennt Unterordner/Wurzel, schreibt Config + Pod + Admin, rebase, löscht sich selbst.
- Auto-Backup (`Backup::auto` nach Admin-Login, `data/backups/`), `bin/lander backup [--media] [--keep]`.
- `src/Image.php`: `?w&ar&fmt` für `/api/media/:id/raw` und `/api/public/media/:id`, Stufen/Whitelists, Cache in `data/cache/media`.
- Getestet (Docker PHP 8.3, Apache, Unterordner /lander/ und flach): Installer, Login/Navigation ohne 404 außerhalb /lander/, Cookie-Pfad, gesperrte Pfade (src, bin, config, .pod), Rebase 2× idempotent, Bildvarianten (webp/png/jpg, Snap, Cache), Auto- und CLI-Backup (.pod, .zip), flache Installation unverändert (Settings-Test).
- Nicht getestet: echter Shared Host, Nginx, `AllowOverride None`-Hosts, PHP ohne gd (Fallback per Code), Restore im Unterordner, Windows-Pfade.

## 2026-11-15 — Benutzerverwaltung
- `Api::users()`: GET/POST `/api/users`, PUT/DELETE `/api/users/{id}` (nur Admin; Rollen admin/editor; Name 2–64 Zeichen, Passwort ≥ 10; eigene Rolle/eigenes Konto nicht änderbar bzw. löschbar; Passwortwechsel meldet die Sitzungen des Benutzers ab). `public/users.html` aus Orbiter, ohne Reviewer, Sammlungs-Rechte und 2FA.
- Getestet (Docker): Anlegen/Validierung/Duplikat/Rolle ungültig→editor, Editor-Rechte (users/backup/restore 403, Inhalte ok), Passwort-Reset (alte Sitzung 401), Löschen, Seite im Browser (anlegen, Reset PW, löschen).
- Hinweis: Öffentliche API cached 60 s + 300 s stale (nginx auf yard hält die Antwort); Änderungen erscheinen bis zu ~6 min später. Option `public_cache_seconds` offen.

## 2026-11-15 — Signiertes Ein-Klick-Update (0.3.1)
- `src/Updater.php`: lädt `lander-X.Y.Z.zip` + `.zip.sig` des neuesten GitHub-Release, prüft Ed25519 (libsodium) gegen `Updater::PUBLIC_KEY`, schreibt nur `src/ bin/ public/` (nicht `install.php`, Config, `data/`, Root-`.htaccess`), davor Rollback in `data/update-rollback/` (Pod + Code-ZIP), danach `Rebase::run` mit der bestehenden Basis. `POST /api/update/apply` (Admin), `GET /api/update` liefert `canApply`/`whyNot`. Knopf im Dashboard-Hinweis und in Einstellungen → Pod.
- Privater Schlüssel: `~/.lander-signing/release.key` (außerhalb der Repos, Backup nötig!). Verlust = Updates nicht mehr signierbar → neuer Schlüssel nur per manuellem Update ausrollbar. Release: `git archive --format=zip -o lander-X.Y.Z.zip HEAD`, `php scripts/sign-release.php lander-X.Y.Z.zip`, beide als Release-Assets hochladen.
- Getestet (Docker mit zip-Erweiterung, gefälschtes Release 0.3.1): manipulierte Signatur → abgewiesen, nichts geändert; gültig → 33 Dateien, Version 0.3.1, Login weiter ok, Rollback-Dateien angelegt. Nicht getestet: echter GitHub-Download (erst ab Release 0.3.1 → 0.3.2 möglich), Shared Host ohne zip.

## 2026-11-15 — Deutsche Oberfläche (0.3.2)
- `public/i18n.js` + `public/i18n-de.js` aus Orbiter (admin 0.3.97): übersetzt Textknoten/Platzhalter/Titel, wenn `localStorage.orb_lang = 'de'` (Einstellungen → Admin-Sprache DE, lädt neu). `theme.js` lädt die Skripte nur dann. Nutzerinhalte (Editor, Inputs, Code) werden nie angefasst. Dictionary ~600 Einträge; Rest bleibt Englisch.
- Release 0.3.2 ist zugleich der echte Test des Updaters (0.3.1 → 0.3.2 über GitHub).

## 2026-11-16 — Zwei-Faktor (TOTP) in Lander (0.3.3)
- `src/Totp.php` (RFC 6238, Format wie Orbiter, Secrets/Recovery-Codes kompatibel), `Api::secondFactor` beim Login (Code oder Recovery-Code, Replay-Schutz über `totp_last`, 10 Versuche/15 min pro Konto zusätzlich zum IP-Limit), `Api::twoFactor` = GET/POST `/api/account/2fa[/setup|enable|disable]` wie Orbiter. Vorher wurde Login für Konten mit 2FA komplett verweigert (jetzt möglich, auch nach Umzug eines Orbiter-Pods).
- `scripts/totp-selftest.php` (RFC-Vektoren). Getestet im Docker: Setup, falscher/richtiger Code, Login ohne/mit falschem Code, Replay (abgewiesen), nächster Zeitschritt, Recovery-Code einmalig, Rest-Anzeige. Nicht getestet: Disable, Authenticator-App am Handy.
- Secret liegt unverschlüsselt im Pod (Orbiter kann at-rest verschlüsseln, Lander noch nicht).
