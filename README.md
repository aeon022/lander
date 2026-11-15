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

## Auf den Server

1. Ordner hochladen, **Document Root auf `public/`** setzen (am besten eigene Subdomain, z. B. `redaktion.example.at`, weil die Oberfläche absolute Pfade nutzt).
2. `lander.config.php` anlegen (`public_origins` = Website-Domain, `media_url` = volle URL).
3. `data/` und `public/media/` für PHP beschreibbar machen.
4. Benutzer per `php bin/lander user …` anlegen (SSH) oder einmalig lokal anlegen und die SQL-Datei `data/content.pod` hochladen.

## Website anbinden

```js
// Laufzeit (sofort aktuell):
const termine = await fetch('https://redaktion.example.at/api/public/termine').then(r => r.json());
// Build-Zeit (SEO, Astro): dieselbe URL im Frontmatter abfragen.
```

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
