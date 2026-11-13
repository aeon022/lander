# stage.md — Lander

## Stand (2026-11-13)
- Gestartet aus Orbiter-Admin (Station-Modus Standard). PHP-API: Login/Logout/Me, Info, Meta, Sammlungen (CRUD, Singleton), Einträge (CRUD, Status, Papierkorb, Versionen, Duplizieren, Sortieren, Bulk), Medien als Dateien (Upload, Alt-Text, Löschen), Markdown-Import (UI + CLI), Suche, öffentliche Lese-API mit CORS.
- Lokal getestet (Docker PHP 8.3) mit curl: Login, falsches Passwort, CSRF, Veröffentlichen, öffentliche API zeigt nur Veröffentlichtes, Bild-Upload, getarnte PHP-Datei abgelehnt, Markdown-Import, Pod nicht abrufbar.
- **Noch nicht getestet:** alle Admin-Seiten im Browser durchklicken (Editor, Medien, Schema, Einstellungen).

## Als Nächstes
1. Admin im Browser durchgehen, fehlende Endpunkte ergänzen (Browser-Konsole/Netzwerk auf 501 prüfen).
2. psychiaterin-graz.at anbinden: Hinweis + Lehrgangstermine live aus `/api/public/…`.
3. Benutzerverwaltung im Admin, 2FA (TOTP), optional Bildverkleinerung (GD).
4. Deployment-Anleitung ipax (Subdomain `redaktion.`).
