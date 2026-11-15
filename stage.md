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
