# Failsafe Backup

A backup plugin for WordPress. It creates a timestamped set with a `.sql` dump of the database and a `.zip` containing the dump plus the site files, lists and manages backups in the admin, restores the database from a ZIP and can run on a schedule.

This repository holds the plugin and the local development environment. Only the contents of `src/` are published to the WordPress.org plugin directory.

Versão em português: [README.pt-BR.md](README.pt-BR.md).

**Status:** 0.2.0, in development. Not published in the WordPress.org plugin directory yet.

## Install

The plugin ships from this repository as a ZIP built out of `src/`:

```sh
./bin/build.sh
```

Then upload `dist/failsafe-backup-<version>.zip` under **Plugins > Add new > Upload plugin**. The build only packs files; it needs no Node, Docker or database.

There is no directory listing yet, so `wp plugin install failsafe-backup` does not work and will not until the plugin is approved.

## Why this plugin

It does one thing: a timestamped `.sql` dump plus a `.zip` of the site files, restorable from the admin, optionally on a schedule.

The scope is deliberately narrow. It has no remote destination, no incremental backup, no multisite and no restore of site files from the screen. If you need any of those, UpdraftPlus or BackWPup already cover them and this plugin is not a replacement. The reason to pick this one is the opposite: the whole plugin lives in `src/`, with tests that run against a real WordPress and MySQL, so you can read it end to end and hook into it.

## Code map

File-by-file map of `src/`, `tests/`, `bin/` and the build inputs: [docs/layout.md](docs/layout.md).

## Current state (0.2.0)

What works today:

- Manual backup from the **Backup now** button, in the dashboard widget or on the **Failsafe** page, protected by a nonce and a capability (`manage_options` by default, filterable through `failsafe_backup_capability`).
- Timestamped sets in the `failsafe-YYYYMMDD-HHMMSS` format, with `.sql` and `.zip`; a new backup never overwrites the previous one. Two runs in the same second get a `-2` to `-9` suffix.
- Dump of every table in the current database, with `DROP TABLE IF EXISTS`, `CREATE TABLE` and one `INSERT` per row, a consistent snapshot, `FOREIGN_KEY_CHECKS = 0`, a final `COMMIT` and a `$wpdb->last_error` check after every query.
- Dump streamed to disk: 500 rows per query, batches of 100 `INSERT` commands and direct writes, keeping memory flat.
- ZIP with the dump at the root plus every readable site file, except the backup directory, `wp-content/cache` and `wp-content/upgrade`.
- Atomic write through `.part` and `WP_Filesystem`; a failed ZIP is deleted so it never looks like a valid backup.
- **Failsafe** page with a table of date, files, size and actions, including `.zip` and `.sql` downloads through `admin-post.php` with a nonce, and deletion by POST with a per-set nonce.
- Retention of complete sets: default 5, range 0 to 50, 0 keeps everything. Incomplete sets stay until deleted by hand.
- Dashboard widget with the latest backup, the number of sets, the total size on disk and a link to the list.
- Database restore under **Tools > Failsafe Restore**, with the backup name typed as confirmation, a full safety backup before the restore, and a clear warning that site files are not changed.
- Restore reads the dump from disk in 64 KB blocks, with an allowlist of statements (`SET`, `DROP/CREATE/ALTER TABLE`, `INSERT`, `REPLACE`, `LOCK/UNLOCK TABLES` and transaction control) and a single commit per run.
- Scheduling with hourly, twice daily, daily and weekly frequencies, a lock that prevents two runs at the same time (scheduled or manual) and takeover of an abandoned lock.
- **Settings > Failsafe Backup** screen with the schedule status (last run, result, next run), frequency and retention.
- Pre-flight checks for memory, disk space and estimated run time before the dump starts.
- Status e-mail for manual backups: failures whenever the administrator address is valid, success only with the `failsafe_email_on_success` option enabled.
- `wp-content/failsafe-backups/` directory created on activation and protected with `.htaccess` and `index.php`.
- Uninstall removes every plugin option and the lock transient; backups are only deleted when `failsafe_delete_backups_on_uninstall` is enabled.

What does **not** exist yet: multisite support, restoring site files from the admin, background processing for large sites, and dumping views, triggers, procedures or events. There are also no fields in the screens for `failsafe_email_on_success` and `failsafe_delete_backups_on_uninstall`; both options have to be set in code or in the options table.

## Requirements

- WordPress 6.2 or newer (tested on 7.1.3, the latest published release).
- PHP 8.0 or newer, as declared in the plugin header and in `readme.txt`.
- PHP `zip` extension (`ZipArchive`) enabled.
- PHP `mbstring` extension (used to detect binary values in the dump).
- Write permission on `wp-content/` for the web server user.
- For backups, a database user allowed to run `SHOW TABLES`, `SHOW CREATE TABLE`, `SELECT` and to read `information_schema`.
- For restores, permission to run `DROP TABLE`, `CREATE TABLE` and `INSERT`, because the dump recreates the tables.
- WP-Cron with site traffic, or a system cron calling `wp-cron.php`, so scheduled backups run on time.
- Single-site install; multisite is not supported.

## Running locally

```sh
docker compose up -d      # site at http://localhost:8080
docker compose down       # stop the containers
```

The containers are `failsafe-wordpress-1` and `failsafe-db-1`. Local credentials live only in `docker-compose.yml`.

## Build

```sh
./bin/build.sh
```

The script packs `src/` into two files: `dist/failsafe-backup.zip` (files at the root, for SVN `trunk/`) and `dist/failsafe-backup-<version>.zip` (everything inside `failsafe-backup/`, for the plugin installer). `src/assets/admin.js` is included in both; `tests/`, `stubs/`, `bin/` and repository files stay out.

## Tests

```sh
./tests/run-tests.sh
./tests/disaster-protocol.sh
```

The first script needs the Docker stack running and runs:

1. `php -l` on `src/failsafe.php`, `src/uninstall.php`, `src/admin/*.php` and `src/includes/*.php`.
2. Tests inside the WordPress container: `test-dump.php`, `test-stream.php`, `test-zip.php`, `test-storage.php`, `test-limits.php`, `test-restore.php`, `test-cron.php` and `test-settings.php`.
3. Round-trip: copies the generated dump, imports it into a disposable `teste_ci` database, imports it again to prove `DROP TABLE` works and compares the row counts of `wp_posts`, `wp_options` and `wp_users`.

The second script creates a marker post, runs a backup, deletes the post, restores the database, confirms the post is back and the site answers, then cleans the marker up.

The test database is created and dropped by the script itself. Never import a dump into a real database.

## Extending

The plugin exposes lifecycle actions and filters so another plugin can react to a backup or change how it runs.

| Hook | Type | Purpose |
|---|---|---|
| `failsafe_backup_started` | action | A run began |
| `failsafe_backup_completed` | action | Set written; receives the result |
| `failsafe_backup_failed` | action | Run failed; receives the `WP_Error` |
| `failsafe_backup_deleted` | action | A set was deleted |
| `failsafe_retention_deleted` | action | Retention removed old sets |
| `failsafe_restore_completed` | action | Database restored |
| `failsafe_backup_dir` | filter | Backup directory |
| `failsafe_backup_capability` | filter | Capability required |
| `failsafe_backup_excluded_paths` | filter | Directories left out of the ZIP |
| `failsafe_dump_tables` | filter | Tables written to the dump |
| `failsafe_dump_chunk_size` | filter | Rows per dump query |
| `failsafe_dump_insert_batch` | filter | `INSERT` per batch |
| `failsafe_required_memory_bytes` | filter | Memory floor |
| `failsafe_cron_enabled` | filter | Scheduled run on or off |
| `failsafe_cron_frequencies` | filter | Available schedules |
| `failsafe_cron_lock_timeout` | filter | Abandoned lock timeout |
| `failsafe_restore_read_chunk` | filter | Restore read block |
| `failsafe_restore_max_entries` | filter | ZIP entry limit |
| `failsafe_status_email_recipient` | filter | Status e-mail recipient |

Writing a remote destination, an alert or a files-restore add-on means hooking `failsafe_backup_completed` and using the set path, never editing the plugin.

## Known limitations

- **Database size**: the largest measured case has 150,000 rows, a 37.8 MB dump and a 42 MB ZIP, produced in 8 seconds with a 42 MB memory peak under a 128 MB limit. Memory stays flat because the dump is streamed; the remaining limit is time.
- **Timeout**: the backup runs inside the admin or WP-Cron request. The 150,000-row case took 8 seconds to back up and 19.3 seconds to restore; under Apache the default limit is 30 seconds. Databases several times larger need background processing, which does not exist yet.
- **WP-Cron depends on traffic**: a scheduled backup only starts when the site receives a request or when `wp-cron.php` is called by a system cron. A site without visits delays the schedule.
- **nginx**: directory protection relies on `.htaccess`. On nginx you need your own server rule, or define `FAILSAFE_BACKUP_DIR` to a directory outside the web root.
- **Multisite**: not supported. On a multisite the dump includes every table in the network and every file in the installation, with no per-site separation.
- **Restore**: only the database is restored; site files are not. A failure halfway through the dump can leave the database partially restored. Only sets with a non-empty ZIP can be restored from the screen, and the temporary directory must be outside the web root.
- **Retention during a restore**: the routine does not delete the backup being restored, but the safety backup also counts toward retention; a set beyond the limit can be removed at that moment.
- **Database objects**: views, triggers, procedures and events are not included in the dump.
- **Retention**: only complete sets are removed; a set missing one file stays until deleted by hand.
- **Lock**: a second run started while a backup is in progress is refused with a warning, not queued; try again after the first run finishes.
- **E-mail**: the status e-mail is sent by manual backups. A scheduled failure is recorded in the status and the log but sends no mail.
- **0.1.0 backups**: the fixed names `failsafe-backup.sql` and `failsafe-backup.zip` are neither listed nor removed by retention.
- **Sensitive data**: the ZIP contains `wp-config.php` and the database dump, including password hashes and personal data. Treat the file as confidential.

## Known debt

- `failsafe_email_on_success` and `failsafe_delete_backups_on_uninstall` have no field in the screens; they have to be set in code or in the options table.
- The restore has no limit on the uncompressed size, only on the number of entries (50,000) and path validation.
- The official text is English, as the directory requires; `src/languages/failsafe-backup.pot` exists, but no translation has been published.

## Roadmap

Restoring site files, multisite support, background processing for large sites and dumping views, triggers, procedures and events are planned. No version or date is promised.

## License

GPLv2 or later. See https://www.gnu.org/licenses/gpl-2.0.html.
