# Code map

Where each part of the plugin lives. Only `src/` goes into the published package.

## Plugin (`src/`)

- `src/` — plugin code (this is what goes to the official directory).
  - `src/failsafe.php` — header, constants (`FAILSAFE_VERSION`, `FAILSAFE_BACKUP_DIR`, `FAILSAFE_RETENTION_DEFAULT`) and the `require_once` map.
  - `src/includes/storage.php` — names, paths, listing, deletion, retention and atomic writes.
  - `src/includes/limits.php` — database and site-file estimates, plus memory, disk and time checks.
  - `src/includes/dump.php` — streaming dump writer.
  - `src/includes/notifications.php` — status e-mail composition and delivery.
  - `src/includes/restore.php` — incremental SQL parser, allowlist, dump execution, validated ZIP extraction and temp directory cleanup.
  - `src/includes/cron.php` — scheduled event, frequencies, lock, execution and last-result storage.
  - `src/admin/setup.php` — creates and protects the backup directory.
  - `src/admin/menu.php` — **Failsafe** menu, page hook and script loading.
  - `src/admin/actions.php` — manual backup handler plus the path, ZIP and orchestration functions.
  - `src/admin/backups-page.php` — **Backups** page, table, empty state and notices.
  - `src/admin/download.php` — `.zip` and `.sql` downloads through `admin-post.php`.
  - `src/admin/delete.php` — deletion by POST with a per-set nonce.
  - `src/admin/dashboard.php` — dashboard widget.
  - `src/admin/restore.php` — screen and handler for **Tools > Failsafe Restore**.
  - `src/admin/settings.php` — settings screen, options, sanitization and schedule status.
  - `src/admin/format.php` — date and size formatting.
  - `src/admin/partials.php` — backup button and table actions.
  - `src/uninstall.php` — removes the options and, with opt-in, the backups.
  - `src/assets/admin.js` — confirmation for destructive actions marked with `data-confirm`.
  - `src/languages/failsafe-backup.pot` — translation template.
  - `src/readme.txt` — WordPress.org directory text.
## Repository

- `bin/build.sh` — builds both packages from `src/`.
- `tests/` — lint, unit tests, streaming, cron, settings, round-trip and the disaster protocol. They live outside `src/` so they never ship inside the plugin package.
- `stubs/` — WordPress stubs for static analysis. Not part of the package.
- `docker-compose.yml` — local environment; not part of the published plugin.
- `.distignore` — files that must not enter the package.
- `CHANGELOG.md` / `CHANGELOG.pt-BR.md` — version history in English and Portuguese.
- `MATURITY.md` / `SUBMISSION.md` — maturity assessment and publishing checklist (Portuguese).
- `AGENTS.md` — project conventions (Portuguese).

