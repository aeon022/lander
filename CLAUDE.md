# Lander — project rules

PHP version of Orbiter for shared hosting. The admin UI in `public/*.html|js|css` comes from `packages/admin/public` in [Orbiter](https://github.com/aeon022/orbiter) and stays as unchanged as possible (changes are marked `Lander:`). The API in `src/Api.php` mirrors Orbiter's paths and JSON shapes (reference: `packages/admin/src/routes/*.js`, schema: `packages/core/src/db.js`).

**Read `stage.md` at the start of a session, add to it at the end.**

- Test: `docker compose up -d` → http://127.0.0.1:8088 ; CLI: `docker compose exec -u www-data lander php bin/lander …`
- Security: session cookie `orb_sess` (HttpOnly, SameSite=Strict), CSRF via origin check, login throttle (5 / 15 min), uploads only with a verified file type, no PHP execution in the media folder, SQL file outside the web root (or `.htaccess` routing in a subfolder).
- Never change the `.pod` schema differently from Orbiter (interchangeability).
- After pulling a new Orbiter admin UI: re-apply the `Lander:` markers and run `php bin/lander rebase` for subfolder installs.
