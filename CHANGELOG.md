# Changelog

All notable changes to this plugin are recorded in this file.

Versão em português: [CHANGELOG.pt-BR.md](CHANGELOG.pt-BR.md).

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses semantic versioning. `src/readme.txt` repeats the history in English for the WordPress.org directory.

## [Unreleased]

### Planned

- Multisite support.
- Restoring site files from the admin.
- Background processing for large sites.
- Dumping views, triggers, procedures and events.

None of these has a version or a date.

## [0.2.0] - 2026-10-24

### Added

- **Failsafe** page in the main menu, with the backup table (date, files, size and actions).
- `.zip` and `.sql` downloads through `admin-post.php`, with a nonce and a path rebuilt from the validated backup name.
- Deletion by POST with a per-set nonce, removing both files at once.
- Incomplete-set warning: a backup missing one of its files is flagged in the table and is not removed by retention.
- Dashboard widget with the latest backup, the number of sets, the total size on disk and the **Backup now** button.
- Timestamped names in the `failsafe-YYYYMMDD-HHMMSS` format, with a numeric suffix when two backups start in the same second.
- Retention of complete sets, default 5, configurable from 0 to 50, with 0 keeping everything.
- Dump streamed to disk, 500 rows per query and batches of 100 `INSERT` commands, keeping memory flat regardless of database size.
- Atomic dump write: a `.part` file moved into place by `WP_Filesystem`; a failed ZIP is deleted so it never looks like a valid backup.
- Database restore under **Tools > Failsafe Restore**, with the backup name typed as confirmation.
- Restore reads the dump from disk in 64 KB blocks and runs each statement as soon as it ends, without loading the whole file.
- Restore limited to the statements a dump needs (`SET`, `DROP/CREATE/ALTER TABLE`, `INSERT`, `REPLACE`, `LOCK/UNLOCK TABLES`, transaction control); any other command aborts the operation.
- Single-commit import per run: a 38 MB dump went from 5m04s to about 19s.
- Full safety backup before a restore; if it fails, the restore is aborted.
- ZIP extracted to a temporary directory outside the web root, rejecting absolute paths, `..` references, null bytes and symbolic links, with a 50,000-entry limit.
- Statement-by-statement error checking during the restore; `SET` failures are logged and skipped, everything else stops the restore.
- The restored backup and the safety backup are not deleted by the restore routine.
- Scheduled backups with hourly, twice daily, daily and weekly frequencies, enabled or disabled in settings and defaulting to daily after activation.
- Run lock for scheduled and manual backups, backed by a transient, with takeover of a lock abandoned for more than 15 minutes.
- Settings screen with the schedule status, last run, last result, next run and retention limit.
- Notices on the settings screen for a late event, a missing event and `DISABLE_WP_CRON`.
- Pre-flight checks for memory (fixed 64 MB floor, since the dump is streamed), disk (about twice the database plus the site files, plus 16 MB) and run time (about 5 seconds plus 1 second per 8 MB of database and files).
- One-hour cache for the total size of the site files, used in the disk calculation.
- Ten-minute cache for the estimated database size, read from `information_schema`.
- Status e-mail for manual backups: failures whenever the administrator address is valid, success only with the `failsafe_email_on_success` option; recipient configurable through the `failsafe_status_email_recipient` filter.
- `failsafe_restore_completed` action fired after a successful restore.
- Lifecycle actions `failsafe_backup_started`, `failsafe_backup_completed`, `failsafe_backup_failed`, `failsafe_backup_deleted` and `failsafe_retention_deleted`, so an add-on can react to a run or a deletion without editing the plugin.
- `failsafe_dump_tables` filter to choose the tables in the dump and `failsafe_backup_excluded_paths` filter to choose the directories left out of the archive; both apply to the size estimates as well.
- `Requires PHP: 8.0` in the plugin header and in `readme.txt`.

### Changed

- The backup stopped using the fixed names `failsafe-backup.sql` and `failsafe-backup.zip` and now creates a timestamped set per run; older versions are never overwritten.
- The dashboard widget is no longer just the backup form and now summarises the latest backup, the number of sets and the total size.
- The result of a manual backup now redirects to the **Backups** page with a success or error notice instead of only a message in the widget.
- Retention runs after every completed backup, manual or scheduled.
- The ZIP holds the dump under the set name (`failsafe-YYYYMMDD-HHMMSS.sql`); the restore also accepts the fixed name used by 0.1.0.
- `Uninstall` removes every plugin option and the lock transient; with `failsafe_delete_backups_on_uninstall` enabled it also removes the backup directory.

### Fixed

- A partial ZIP no longer shows up as a valid backup: the file is deleted when creation fails.
- Simultaneous backups no longer overwrite each other; collisions in the same second get a suffix.
- Database errors during the dump still stop the run instead of producing an incomplete file.
- The restore never writes site files into the web root: the extracted content is validated and discarded.
- A safety backup is created before any write to the database; without it, the restore does not start.
- The object cache is flushed after a restore, even when it fails halfway.

### Security

- Every backup, download, deletion and restore action requires a capability (`manage_options` by default, filterable) and a nonce.
- Deletion only accepts POST, with a set-specific nonce.
- Backup names are validated by regular expression before becoming a file path.
- Downloads validate the real path against the backup directory and refuse symbolic links.
- ZIP extraction rejects absolute paths, parent-directory references, null bytes and symbolic links before extracting any entry.
- The backup directory stays protected by `.htaccess` (Apache) and `index.php`; nginx needs its own rule.
- The ZIP contains `wp-config.php` and the database dump and must be treated as sensitive data.

### Known

- The dump is streamed with constant memory; the remaining limit is request time.
- The backup runs inside the admin or WP-Cron request and can hit `max_execution_time`; there is no background processing.
- WP-Cron only runs with traffic; sites without visits can delay a scheduled backup.
- Directory protection relies on `.htaccess`; nginx needs its own rule.
- Multisite is not supported.
- The restore covers only the database; site files are not restored.
- A restore interrupted by an error can leave the database partially restored.
- Views, triggers, procedures and events are not included in the dump.
- Retention does not remove incomplete sets; they stay until deleted by hand.
- The safety backup counts toward retention; a set beyond the limit can be removed at that moment, including the one that would be restored.
- A second run started while a backup is in progress is refused with a warning; it is not queued.
- Status e-mails are sent only by manual backups; a scheduled failure is recorded in the status and the log.
- The `failsafe_email_on_success` and `failsafe_delete_backups_on_uninstall` options have no field in the settings screens.
- 0.1.0 backups with fixed names are neither listed nor removed by retention.

## [0.1.0] - 2026-10-24

### Added

- **Failsafe Backup** dashboard widget with the **Backup now** button.
- `admin_post_failsafe_run_backup` handler with a nonce and a capability check (`manage_options` by default, filterable through `failsafe_backup_capability`).
- SQL dump of every table in the current database, with a site and date header, `SET SESSION sql_mode = ''`, `SET NAMES` using the database charset and collation, a consistent snapshot, `DROP TABLE IF EXISTS`, `CREATE TABLE` and one `INSERT` per row.
- `$wpdb->last_error` checked after every query; an error becomes a `WP_Error` and stops the backup instead of producing a partial file.
- Hexadecimal literals for binary or non-UTF-8 values.
- ZIP with the dump at the root plus every readable site file, except the backup directory, `wp-content/cache` and `wp-content/upgrade`.
- `wp-content/failsafe-backups/` created and protected on activation and on every admin request, with `.htaccess` and `index.php`.
- Success notice in the admin with the dump and ZIP sizes.
- Conditional logging to `wp-content/debug.log` when `WP_DEBUG` is on.
- Clean uninstall: removes `failsafe_retention_count` and, when enabled, `failsafe_delete_backups_on_uninstall`; backups are only deleted with the opt-in enabled.
- Storage layer with timestamped names, listing, deletion and retention (no interface yet).
- Test suite with lint, container unit tests and a dump import/reimport round-trip.

### Security

- Form protected by a nonce and a capability.
- Admin output escaped.
- Backup name validated by regular expression before becoming a file path.
- Backup directory blocked by `.htaccess` (Apache) and an `index.php` file.

### Known

- The backup runs inside the admin request and can hit time or memory limits on large sites.
- Directory protection relies on `.htaccess`; nginx needs its own rule.
- Multisite is not supported.
- Views, triggers, procedures and events are not included in the dump.
- Only the most recent backup is kept: the file names are fixed and get overwritten.
- Restore, scheduling and the backup list do not exist yet.
- The ZIP contains `wp-config.php` and the database dump and must be treated as sensitive data.
