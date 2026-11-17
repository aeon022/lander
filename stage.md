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

## 2026-11-16 — Webhooks und Build-Trigger (0.4.0)
- `src/Webhooks.php`: `build.webhook_url` (Meta, admin-only, nur http/https) wird bei „publish" per POST gerufen; signierte Hooks (`_meta webhooks.urls`, Events publish/delete/review/*, HMAC-SHA256 `X-Orbiter-Signature`, Log der letzten 50). `Api::webhooks` = GET/POST/DELETE/test wie Orbiter. Versand nach der Antwort (`register_shutdown_function` + `fastcgi_finish_request`), 1 Versuch, kein Retry (ponytail-Kommentar).
- Ausgelöst bei: Anlegen mit Status published, Draft→published, Bulk publish/delete, Löschen.
- Getestet (Docker): Hook + Build-URL feuern bei publish (Einzel + Bulk), Entwurf feuert nicht, Test-Ping, ungültige URLs (javascript:, file:) abgewiesen, Signatur-Header vorhanden. Nicht getestet: Signatur gegen Empfänger-Code geprüft, Hosts ohne curl, `review`-Event (Lander hat keinen Review-Ablauf).

## 2026-11-16 — Digi-ID (0.5.0)
- `src/Secp256k1.php` (Public-Key-Recovery, GMP oder bcmath), `src/DigiId.php` (Adresse/Nachrichten-Hash/Verify, Challenges als Dateien in `data/digiid-*.json`, 5 min, einmalig, Pfad inkl. `base_path`), `Api::digiidPublic` (challenge/callback/status, vor dem CSRF-Check, Rate-Limit) und `Api::digiidAccount` (Liste/Verknüpfen/Entfernen mit Passwort), `Api::startSession` aus `login()` herausgelöst, Tabelle `_digiid` im Pod-Bootstrap. UI wie Orbiter (`login.html`, `account.html`, `qrcode.js`, `digiid.js`).
- Getestet (Docker, PHP 8.3 + gmp/bcmath/zip): `scripts/secp-selftest.php` mit 25 noble-Vektoren (GMP 4 ms, bcmath 293 ms pro Recovery, beide ok); Ende-zu-Ende mit simulierter Wallet für komprimierte und unkomprimierte Schlüssel (Verknüpfen, Replay abgewiesen, falscher Poll 404, Login + Cookie, unbekannte Adresse 403, Entfernen). Nicht getestet: Unterordner-Installation, echte Wallet, Host nur mit bcmath (nur Rechenkern geprüft).
- Update-/Digi-ID-POSTs senden jetzt JSON-Body (Plesk/nginx lieferte bei Body-losem POST 403).

## 2026-11-16 — Fix Konto-Seite (0.5.1)
- `router.js` tauschte `account.html` im SPA-Modus nur als `.main` aus: das Modul-Skript und die klassischen Skripte (`qrcode.js`, `digiid.js`) liefen nicht → 2FA-Karte blieb „Loading…", Digi-ID-Knopf tot, bis ein Hard-Refresh kam. Seite jetzt von der SPA-Navigation ausgenommen (wie users/forms). In Puppeteer reproduziert und behoben.

## 2026-11-16 — Medien-Vorschaubilder (0.5.2)
- Melder: im Admin keine Vorschaubilder in der Medienübersicht (Pinas Testserver). `/api/media/:id/raw` und `/api/public/media/:id` leiteten per 302 auf die statische URL (`/lander/media/x.jpg`) um; auf Hosts, bei denen nginx statische Dateien selbst ausliefert (Plesk) oder im Unterordner, erreicht die Anfrage `public/media` nie (404). Lokal (Apache, Wurzel und Unterordner) nicht reproduzierbar, Ursache am echten Server **nicht** bestätigt.
- Fix: `Api::sendMedia` liefert die Datei selbst aus (Pfad geprüft gegen `media_dir`, `nosniff`, CSP-sandbox, Cache), Redirect nur noch, wenn die Datei nicht auf der Platte liegt. Getestet im Docker: raw/public 200 mit Bild, Medienseite zeigt Vorschau.

## 2026-11-16 — Medienordner nicht mehr im Release (0.5.3)
- Ursache der fehlenden Vorschaubilder auf Pinas Testserver (laut Nutzer): beim Hochladen des Update-Pakets wurde `public/media` ersetzt. Das Paket enthielt `public/media/.htaccess`, „Ersetzen" des Ordners löscht die bisherigen Uploads. Die Dateien sind dort weg (Pod-Zeilen bleiben), Wiederherstellung nur durch erneutes Hochladen.
- Fix: `.htaccess` des Medienordners liegt jetzt als Vorlage in `src/media.htaccess`; `public/media/` ist nicht mehr im Repo/ZIP. `install.php`, jeder Upload (`Api::guardMedia`) und der Updater legen die Datei selbst an bzw. erneuern sie. Getestet: Ordner fehlt im Baum, Upload legt ihn samt `.htaccess` an, PHP-Datei dort → 403.
- Pakete für Pina enthalten keinen `media`-Ordner mehr.

## 2026-11-16 — Dock: Reihenfolge und kürzere Namen (0.5.4)
- `nav.order` (JSON-Liste von Sammlungs-IDs) in Meta + `/api/info` (`nav.order`); `xfce.js` (`orbNavSort`) und `sidebar.js` sortieren danach, nicht genannte folgen in der alten Reihenfolge. Dock-Beschriftungen nutzen `ORB_DE_DOCK` (kürzere deutsche Wörter).
- Pina: Reihenfolge Hinweise, Urlaub, Termine, Blog über `PUT /api/meta` gesetzt.

## 2026-11-16 — Autosave für alle Felder (0.5.5)
- Bisher speicherten nur Titel und Text-Blöcke automatisch (2 s); Felder wie Knopf-Text, Datum, Schalter nur per Knopf. Jetzt delegierter `input`/`change`-Listener auf `#fields-card-body, #fields-source, .main-fields` → `scheduleAutosave()` (Status bleibt wie er ist: veröffentlichte Einträge gehen sofort live). Getestet in Orbiter (Puppeteer) und Lander (Docker, Singleton «hinweis»). Nicht abgedeckt: Bildfelder/Pickers (setzen Werte per Code ohne Ereignis).

## 2026-11-16 — Audit: Dateirechte (0.5.6)
- Pod (`Pod::__construct`), Backups (`Backup::snapshot`) und Update-Rollback-ZIP werden mit 0600 angelegt bzw. nachgezogen, Backup-Ordner 0700; Grund: ein Web-Server, der `data/.htaccess` ignoriert, soll sie nicht ausliefern können. `SECURITY.md` ergänzt, GitHub-Sicherheitsfunktionen aktiviert.

## 2026-11-16 — Strikte CSP für die Lander-Oberfläche (0.6.0)
- `public/index.php` liefert Admin-Seiten (`/<name>.html` und Kurz-URL `/<name>`) selbst aus: Nonce an jedem `<script>`, `Content-Security-Policy` (wie Orbiter: `script-src 'nonce-…' 'strict-dynamic' 'self'`, kein `unsafe-inline` für Skripte, `frame-ancestors 'self'`), `X-Frame-Options: DENY`. `public/.htaccess` leitet `*.html` dorthin. Alle 81 Inline-Handler → `data-on-*` (Dispatcher aus `theme.js`, `orbH`-Helfer; `theme.js` auf Orbiter-Stand inkl. Skript-Ladung per `createElement`).
- Getestet (Docker, PHP 8.3/Apache): Hauptordner und Unterordner `/lander`, Deutsch und Englisch: alle 11 Seiten ohne CSP-Verstoß/Seitenfehler, alle Handler-Funktionen vorhanden, alle Klick-Elemente (ohne Löschen) ausgelöst. Nicht einzeln durchgeklickt: jede Editor-Aktion (Tabellen, Video, KI) – wie bei Orbiter.
- Achtung: Hosts ohne mod_rewrite/`.htaccess` (nur nginx) bedienen Lander ohnehin nicht (API-Routing).

## 2026-11-16 — Dock: nicht mehr zu breit (0.6.1)
- Melder: viele Sammlungen sprengten die Dock-Länge, lange Namen standen ungekürzt darunter (Tappauf: 10 Sammlungen). Gruppen gibt es schon (Einstellungen → Navigation), aber sie müssen von Hand angelegt werden und ordnen nach Thema, nicht nach Art; eine automatische Gruppe «Seiten/Listen» wurde deshalb wieder verworfen.
- `xfce.js`: bleiben mehr als 6 Sammlungen ohne Gruppe, wandern die überzähligen in einen Auffang-Drawer «Mehr» (de: «Mehr»), so wird der Dock nie breiter als der Bildschirm. Dock-Namen kürzen auf den ersten Teil des Labels («Referenzen (Projekte)» → «Referenzen», «Auftraggeber & Partner» → «Auftraggeber»), der volle Name steht im Tooltip. Kein «…» mehr.
- `style.css`: Dock `max-width: calc(100vw - 16px)`, Drawer-Popups scrollen ab 70 vh. `i18n-de.js`: «More» → «Mehr».
- Getestet (Docker, Tappauf-Daten, 10 Sammlungen): mit Gruppen 13 statt 16 Dock-Einträge; ohne Gruppen 5 Sammlungen + «Mehr». Tappauf bekommt drei thematische Gruppen (Auftritt, Arbeit, Menschen) in seinem Pod.

## 2026-11-17 — Eintragsliste mit Spalten (0.6.2)
- Neu im Schema-Editor pro Feld: Haken «In list» (`list: true` im Feldschema). Solche Felder erscheinen als Spalten in der Eintragsliste (`entries.html`), Datumsfelder deutsch formatiert, Spalten sortierbar.
- Sortierung: gibt es ein Datumsfeld in der Liste, ist es die Standardsortierung (neueste zuerst); Sortieren nach einer Spalte (z. B. Band) sortiert bei Gleichstand nach Datum (neueste zuerst), dann nach Titel. Mit Spalten entfällt die Spalte «Updated» (Platz).
- Anlass: Konzerte von martin-burboeck.com (eine Band spielt mehrere Konzerte → nach Band und Datum ordnen). Getestet im Browser (Headless-Chrome, Docker, 16 Konzerte): Standardsortierung, Klick auf «Band», keine Konsolenfehler.
- 2026-11-17 Fehler beim Neuanlegen eines Eintrags: Wurde im Dialog ein Slug mit Großbuchstaben getippt (z. B. «Test-test»), speichert der Server ihn klein («test-test»), der Editor öffnete aber den getippten Slug → 404 bei jedem Speichern («Error»). Jetzt: Slug wird klein geschrieben und der vom Server gelieferte Slug für den Editor-Link benutzt (`entries.html`).
- Optionale Auswahlfelder (select, nicht «required») haben jetzt eine leere Option «—»; vorher war immer der erste Wert gesetzt (neues Konzert bekam automatisch die erste Band) (`editor.html`).
- 2026-11-17 Echter Webhost (IPAX, Apache): `public/media/.htaccess` mit `Options -Indexes -ExecCGI` ergab HTTP 500 für alle Medien (`-ExecCGI` ist dort per AllowOverride nicht erlaubt). Jetzt nur `Options -Indexes`; Ausführung von PHP bleibt durch RemoveHandler/RemoveType/FilesMatch verhindert (`src/media.htaccess`).

## 2026-11-17 — Für Künstler: Handy, iPad, Musik, Vorschau (0.7.0, nur lokal, Branch feat/artists, nicht veröffentlicht)
- **Schnell anlegen:** Felder, die im Schema «In list» tragen, erscheinen direkt im Dialog «Neuer Eintrag» (Datum, Band, Ort …) plus Haken «gleich veröffentlichen»; der Slug entsteht aus Titel + Datum (eindeutig, auch wenn dieselbe Band an mehreren Tagen spielt).
- **Handy/iPad:** Listen werden Karten (antippen = bearbeiten), Tab-Leiste mit Beschriftung, 16px-Felder (kein iOS-Zoom), Safe-Areas; installierbar als App (manifest.webmanifest, Icons, iOS-Meta in theme.js).
- **Musik/Video:** Uploads MP3, M4A, WAV, OGG, FLAC, MP4, WebM, MOV (Upload-Limit für neue Installationen 40 MB); Range-Requests für Safari; Vorschau-Player in der Medienansicht.
- **Verknüpfung:** `relation` mit `multiple:false` = eine Auswahl (Dropdown, speichert die Eintrags-ID); die öffentliche API liefert `id` je Eintrag; Listen zeigen den Titel der Verknüpfung.
- **Kopieren mit neuem Datum** (Sammlungen mit Datumsfeld in der Liste), **Klartext-Fehler** statt «Error» beim Speichern, KI-Knöpfe ausgeblendet.
- **Vorschau:** Knopf «Vorschau» im Editor, wenn `site.preview` (JSON: {"events": "/events/?vorschau={slug}"}) gesetzt ist; `site.url` = Adresse der Website. Die Website liest Entwürfe über die Admin-API (angemeldet, gleicher Ursprung).
- **Redakteur-Rolle:** Dock ohne Nutzer/Schema/Build/Import …; der Server verweigert diese Aktionen ohnehin (403).
