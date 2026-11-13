# stage.md — Lander

## Stand (2026-11-13)
- Gestartet aus Orbiter-Admin (Station-Modus Standard). PHP-API: Login/Logout/Me, Info, Meta, Sammlungen (CRUD, Singleton), Einträge (CRUD, Status, Papierkorb, Versionen, Duplizieren, Sortieren, Bulk), Medien als Dateien (Upload, Alt-Text, Löschen), Markdown-Import (UI + CLI), Suche, öffentliche Lese-API mit CORS.
- Lokal getestet (Docker PHP 8.3) mit curl: Login, falsches Passwort, CSRF, Veröffentlichen, öffentliche API zeigt nur Veröffentlichtes, Bild-Upload, getarnte PHP-Datei abgelehnt, Markdown-Import, Pod nicht abrufbar.
- **Im Browser getestet (13.11., eingeloggt, Headless-Chrome, Netzwerk + Konsole):** Dashboard, Einträge, Editor (auch Singleton), Medien, Sammlungen, Schema, Import, Einstellungen, Konto: keine 4xx/5xx, keine JS-Fehler. Dock mit Sammlungen sichtbar.
- Behoben: Dock fehlte (sidebar.js lud xfce.js nur bei gespeichertem orb_style; `/api/info` ohne `podPath` brach das Dock ab).
- Noch sichtbar, aber ohne Funktion: KI-Knöpfe im Editor («AI», «Suggest»), Bereiche Webhooks/API-Schlüssel/Vorlagen in den Einstellungen (leer).

## Als Nächstes
1. KI-Knöpfe und leere Einstellungsbereiche ausblenden; Klick-Tests (Speichern, Upload über Oberfläche).
2. psychiaterin-graz.at anbinden: Hinweis + Lehrgangstermine live aus `/api/public/…`.
3. Benutzerverwaltung im Admin, 2FA (TOTP), optional Bildverkleinerung (GD).
4. Deployment-Anleitung ipax (Subdomain `redaktion.`).
