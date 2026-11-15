# Lander

> Teil von **[Orbiter](https://github.com/aeon022/orbiter)** — „CMS in one POD“ für Astro. Lander ist der Weg für Hosting ohne Node. Überblick: [orbiter.sh/lander](https://orbiter.sh/lander) · Doku: [orbiter.sh/docs/lander](https://orbiter.sh/docs/lander)

**Orbiter auf gewöhnlichem PHP-Hosting.** Orbiter bleibt im Orbit (Node-Server), der Lander setzt auf jedem Webhost mit PHP 8.1+ und SQLite auf. Gleiche SQL-Datei (`.pod` — eine normale SQLite-Datenbank, nur umbenannt), gleiche Station-Oberfläche, kein Node, kein Build-Server.

- **Admin:** die Orbiter-Oberfläche (Station-Modus) unverändert als statische Dateien, dahinter eine PHP-API mit denselben Pfaden und JSON-Formaten.
- **Live einlesen:** Websites lesen veröffentlichte Inhalte zur Laufzeit über `/api/public/<sammlung>[/<slug>]` (CORS für eingetragene Domains, 60 s Cache). Änderungen sind sofort sichtbar, ohne Neubau.
- **Markdown-Import:** `.md` mit Frontmatter über Admin → Import oder `bin/lander import-md`.
- **Medienfelder aufgelöst:** In der Lese-API werden Bild-, Datei- und Medienfelder zu `{id, url, alt, type}` (volle URL).
- **Medien als Dateien:** Uploads landen in `public/media/<ordner>/`, nicht in der Datenbank. Erlaubt: JPG, PNG, WebP, GIF, AVIF, PDF (echter Dateityp geprüft). PHP-Ausführung im Medienordner gesperrt.

## Schnellstart (lokal)

```bash
cp lander.config.example.php lander.config.php
docker compose up -d                       # PHP 8.3 + Apache auf http://127.0.0.1:8088
docker compose exec -u www-data lander php bin/lander user <name> admin
docker compose exec -u www-data lander php bin/lander collection blog "Blog"
docker compose exec -u www-data -w /var/www/lander lander php bin/lander import-media <ordner> <medienordner>
```

## Auf den Server (Installer)

1. Den Ordner hochladen: entweder in einen **Unterordner** der Website (z. B. `psychiaterin-graz.at/lander/`) oder auf eine **Subdomain** (Document Root = `public/`).
2. `…/install.php` im Browser öffnen (im Unterordner `…/lander/install.php`). Der Installer prüft PHP, erkennt **selbst, ob Unterordner oder Domain-Wurzel**, legt `lander.config.php`, den Pod und den ersten Admin an, passt die Oberfläche an den Ordner an und **löscht sich danach selbst**.
3. Anmelden, fertig. Backups (siehe unten) laufen von selbst.

Im Unterordner leitet die `.htaccess` im Lander-Ordner alles nach `public/` um; `src/`, `data/` (die `.pod`) und `lander.config.php` sind von außen nicht erreichbar. Voraussetzung: Apache mit `mod_rewrite` und `AllowOverride All`, wie bei fast jedem Webhost. Auf Nginx die Pfade außerhalb von `public/` selbst sperren.

Manuell: `lander.config.example.php` kopieren, `base_path` setzen, `php bin/lander rebase /lander`, Benutzer mit `php bin/lander user <name> admin`.

## Backup, Wiederherstellen, Umzug

- **Automatisch:** Beim Login eines Admins sichert Lander, wenn das letzte Backup älter als `auto_backup_days` (7) ist, nach `data/backups/auto-*.pod` und behält `auto_backup_keep` (8). Ohne Cron.
- **Cron / Hand:** `php bin/lander backup [--media] [--keep=N]` (`--media` = ZIP mit Bildern).
- **Admin:** Einstellungen → Pod → Backup & restore (Download, Restore). Restore prüft die Datei, sichert den alten Pod vorher (`data/before-restore-*.pod`) und passt Medienpfade an den neuen Host an. So zieht man auch um (Unterordner ↔ Subdomain: `php bin/lander rebase …`).

## Bildgrößen

`/api/public/media/<id>?w=800&ar=16:9&fmt=webp` liefert ein skaliertes, optional zugeschnittenes (Fokuspunkt aus der Mediathek) und umkodiertes Bild. Breiten rasten auf feste Stufen ein (160 … 2400), Seitenverhältnisse und Formate (`jpg`, `png`, `webp`, `avif`) sind begrenzt, es wird nie hochskaliert. Varianten liegen in `data/cache/media/`. Braucht PHP `gd`; ohne gd wird das Original ausgeliefert.

## Website anbinden

```js
// Laufzeit (sofort aktuell):
const termine = await fetch('https://redaktion.example.at/api/public/termine').then(r => r.json());
// Build-Zeit (SEO, Astro): dieselbe URL im Frontmatter abfragen.
```

## Backup & Wiederherstellen (Umzug)

Einstellungen → Pod → **Backup & restore** (nur Admins):

- **SQL-Datei (.pod):** konsistente Kopie der Datenbank ohne Sitzungen. Klein, schnell.
- **Mit Medien (ZIP):** `content.pod` plus `media/…` (braucht die PHP-Erweiterung `zip`).
- **Restore:** `.pod` oder Backup-ZIP hochladen. Der Server prüft die Datei (SQLite, Integrität, Tabellen, mindestens ein Admin), legt vorher eine Kopie ab (`data/before-restore-*.pod`, die letzten 3), ersetzt den Pod, entpackt Medien (nur Bilder/PDF, echter Dateityp) und schreibt Pfade/URLs für den neuen Host um. Danach Abmeldung.
- Limits: `max_restore_mb` (Standard 100) und die PHP-Werte `upload_max_filesize` / `post_max_size`. Große Medien-ZIPs lieber per FTP einspielen.
- Backups enthalten Passwort-Hashes: privat aufbewahren.

## Unterschiede zu Orbiter

| | Orbiter | Lander |
|---|---|---|
| Laufzeit | Node (Hono) | PHP 8.1+ (PDO SQLite) |
| Medien | BLOB in der SQL-Datei oder S3 | Dateien im Ordner |
| Passwörter | scrypt | `password_hash` (Orbiter-Benutzer: `bin/lander passwd`) |
| Bereiche | alle | Dashboard, Sammlungen, Einträge, Editor, Medien, Import, Einstellungen, Konto |
| Posteingang (Inbox): Einsendungen speichern, Status, Löschen | ja | ja, Annahme nur Server-zu-Server mit `form_token` (keine öffentliche Formular-API, kein Antworten aus dem Admin) |
| 2FA, Workflow, Webhooks, Analytics, Form Builder | ja | noch nicht |

Lizenz MIT, Oberfläche aus [Orbiter](https://github.com/aeon022/orbiter).
