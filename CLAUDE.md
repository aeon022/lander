# Lander — Projektregeln

PHP-Version von Orbiter für Shared Hosting. Die Admin-Oberfläche in `public/*.html|js|css` stammt aus `~/Sites/orbiter/packages/admin/public` und bleibt möglichst unverändert (Änderungen markiert mit «Lander:»). Die API in `src/Api.php` bildet Orbiters Pfade und JSON-Formate nach (Vorbild: `~/Sites/orbiter/packages/admin/src/routes/*.js`, Schema: `packages/core/src/db.js`).

**Zu Sessionbeginn `stage.md` lesen, am Ende ergänzen.**

- Test: `docker compose up -d` → http://127.0.0.1:8088 ; CLI: `docker compose exec -u www-data lander php bin/lander …`
- Sicherheit: Session-Cookie `orb_sess` (HttpOnly, SameSite=Strict), CSRF per Origin-Prüfung, Login-Bremse (5 / 15 min), Uploads nur mit geprüftem Dateityp, kein PHP im Medienordner, Pod außerhalb des Webroots.
- Schema der `.pod` nie abweichend von Orbiter ändern (Austauschbarkeit).
- Erster Einsatz: psychiaterin-graz.at (Hinweis, Lehrgangstermine, später Blog).
