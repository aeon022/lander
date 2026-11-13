# Lander

**Orbiter auf gewöhnlichem PHP-Hosting.** Orbiter bleibt im Orbit (Node-Server), der Lander setzt auf jedem Webhost mit PHP 8.1+ und SQLite auf. Gleiche `.pod`-Datei, gleiche Station-Oberfläche, kein Node, kein Build-Server.

- **Admin:** die Orbiter-Oberfläche (Station-Modus) unverändert als statische Dateien, dahinter eine PHP-API mit denselben Pfaden und JSON-Formaten.
- **Live einlesen:** Websites lesen veröffentlichte Inhalte zur Laufzeit über `/api/public/<sammlung>[/<slug>]` (CORS für eingetragene Domains, 60 s Cache). Änderungen sind sofort sichtbar, ohne Neubau.
- **Markdown-Import:** `.md` mit Frontmatter über Admin → Import oder `bin/lander import-md`.
- **Medien als Dateien:** Uploads landen in `public/media/<ordner>/`, nicht in der Datenbank. Erlaubt: JPG, PNG, WebP, GIF, AVIF, PDF (echter Dateityp geprüft). PHP-Ausführung im Medienordner gesperrt.

## Schnellstart (lokal)

```bash
cp lander.config.example.php lander.config.php
docker compose up -d                       # PHP 8.3 + Apache auf http://127.0.0.1:8088
docker compose exec -u www-data lander php bin/lander user <name> admin
docker compose exec -u www-data lander php bin/lander collection blog "Blog"
```

## Auf den Server

1. Ordner hochladen, **Document Root auf `public/`** setzen (am besten eigene Subdomain, z. B. `redaktion.example.at`, weil die Oberfläche absolute Pfade nutzt).
2. `lander.config.php` anlegen (`public_origins` = Website-Domain, `media_url` = volle URL).
3. `data/` und `public/media/` für PHP beschreibbar machen.
4. Benutzer per `php bin/lander user …` anlegen (SSH) oder einmalig lokal anlegen und `data/content.pod` hochladen.

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
| Medien | BLOB im Pod oder S3 | Dateien im Ordner |
| Passwörter | scrypt | `password_hash` (Orbiter-Benutzer: `bin/lander passwd`) |
| Bereiche | alle | Dashboard, Sammlungen, Einträge, Editor, Medien, Import, Einstellungen, Konto |
| 2FA, Workflow, Webhooks, Analytics, Formulare | ja | noch nicht |

Lizenz MIT, Oberfläche aus [Orbiter](https://github.com/aeon022/orbiter).
