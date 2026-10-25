=== Failsafe Backup ===
Contributors: felipealbino
Tags: backup, database, restore, schedule, zip
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creates timestamped ZIP backups of the database and site files, restores the database and can run on a schedule.

== Description ==

Failsafe Backup creates a ZIP archive with the site files and a SQL dump of the database, keeps the previous backups under a retention limit, and can restore the database from any complete archive. It works on a single WordPress installation; multisite is not supported.

Each backup is a set of two files in `wp-content/failsafe-backups/`:

* `failsafe-YYYYMMDD-HHMMSS.zip` — the SQL dump plus every readable file in the WordPress installation.
* `failsafe-YYYYMMDD-HHMMSS.sql` — the same database dump on its own.

Names use the site time zone and are never reused: if two runs start in the same second, the second one gets a `-2` suffix. A new backup never overwrites an older one; old sets are removed only by the retention limit or by hand.

**The database dump**

* Starts with the site URL, the UTC date, `SET SESSION sql_mode = ''` and `SET NAMES` with the database charset and collation.
* Disables foreign key checks and opens a consistent snapshot before reading the tables.
* Writes `DROP TABLE IF EXISTS` and `CREATE TABLE` for every table, followed by one `INSERT` per row.
* Ends with `COMMIT` and re-enables foreign key checks.
* Checks `$wpdb->last_error` after every query, so a missing table or a failed read stops the backup instead of producing a partial file.
* Writes binary or non-UTF-8 values as hexadecimal literals.
* Includes every table in the current database, including tables created by other plugins.
* Does not include views, triggers, stored procedures or events.

**The ZIP archive**

* Contains the SQL dump at the archive root and the site files under their original relative paths.
* Excludes the backup directory itself, `wp-content/cache` and `wp-content/upgrade`.
* Includes `wp-config.php`, so treat the archive as sensitive data.
* If a file cannot be added, the run fails and the partial archive is removed.

**Managing backups**

The top-level **Failsafe** menu opens the **Backups** screen. The table shows the date, the file names, the total size and the actions for every backup set:

* **Download (.zip)** and **Download (.sql)** stream one file through `admin-post.php`; the link carries a nonce and the path is rebuilt from the requested backup name.
* **Delete** removes both files of the set. It is a POST request with a nonce tied to that set, so a stale link cannot delete another backup.
* A set with only one file is marked **Incomplete** and is kept until it is deleted by hand. Retention never removes it.

The dashboard widget shows the latest backup, the number of sets and the total size on disk, with a link to the full screen and a **Backup now** button. The **Backups** screen also has a **Backup now** button.

**Restoring the database**

**Tools > Failsafe Restore** restores the database from the `.zip` archive of a backup set. The screen shows a restore form only for sets whose ZIP exists and is not empty.

1. Type the exact backup name to confirm the operation.
2. The plugin creates a full safety backup first. If that backup fails, the restore is aborted.
3. The archive is extracted to a temporary directory outside the web root. Entries with absolute paths, `..` references, null bytes or symbolic links are rejected before any archive entry is written, and archives with more than 50,000 entries are refused by default.
4. Only the SQL dump is executed. The site files in the archive are validated and then discarded; the files of the installation are not changed.
5. The restore routine does not delete the backup it used, and the safety backup is kept too. Both count toward the retention limit. The object cache is flushed when the process finishes.

A failing `SET` statement is logged and skipped, because restricted database users may not be allowed to change every session value. Any other failing statement stops the restore and shows the error, so a restore can leave the database partially restored. You may need to sign in again after the database changes.

The restore screen cannot restore a `.sql` file on its own, and it does not restore site files. To recover files, extract the ZIP over the WordPress directory manually.

**Automatic backups**

**Settings > Failsafe Backup** controls scheduled backups:

* The schedule is enabled by default with a daily frequency after activation.
* Frequencies: hourly, twice daily, daily and weekly.
* Changing the frequency replaces the existing event; it never creates a second event.
* The same screen shows the last scheduled run, its result and the next due time, and warns when the event is overdue, missing or when `DISABLE_WP_CRON` is set.
* A lock prevents two scheduled backups from running at the same time. A lock older than 15 minutes is treated as abandoned and can be taken over.

WP-Cron only runs when the site receives traffic. A quiet site can therefore run an automatic backup late. To keep the schedule on time, disable WP-Cron and call `wp-cron.php` from a system cron job.

The status e-mail is sent for manual backups started from the admin screens: a failure is reported whenever the administrator address is valid, and a success is reported only when the `failsafe_email_on_success` option is enabled (there is no setting for it in the admin screens yet). The message goes to the site administrator address and can be redirected with the `failsafe_status_email_recipient` filter. A failed scheduled run is recorded in the settings status and in the site log, but it does not send an e-mail.

**Retention**

The **Backups to keep** setting defaults to 5 and accepts 0 to 50. Zero keeps every backup. The limit is applied after every successful backup, manual or scheduled, and only complete sets count. Sets left incomplete by an interrupted run stay on disk until they are deleted.

**Pre-flight checks**

Before writing any file, the plugin estimates the database size from `information_schema` (cached for 10 minutes) and checks three limits:

* Memory: about three times the database size against the PHP `memory_limit`.
* Disk: about twice the database size plus 16 MB against the free space of the backup directory.
* Time: about five seconds plus one second per 2 MB against `max_execution_time`.

A backup that would exceed a known limit is stopped with an explanatory error before the dump starts. When PHP does not expose a limit, that check is skipped.

**The backup directory**

The plugin creates `wp-content/failsafe-backups/` on activation and on every admin request if it is missing. The directory receives an `.htaccess` file that denies direct access and an empty `index.php`. Define `FAILSAFE_BACKUP_DIR` in `wp-config.php` before the plugin loads to use another absolute path.

Downloads do not rely on direct access to that directory: they are served by `admin-post.php` after the capability and nonce checks.

**Who can run a backup**

Users with the `manage_options` capability by default. The capability can be changed with the `failsafe_backup_capability` filter.

**Uninstall**

Deactivation removes the scheduled event and any run lock; the backups are left untouched. Uninstall removes the retention setting. The backup directory is kept unless the site owner has enabled the `failsafe_delete_backups_on_uninstall` option; when enabled, the directory and that option are removed. The scheduling, status, e-mail and database-size cache options are not removed in this version.

== Installation ==

1. Upload the `failsafe` directory to `/wp-content/plugins/`, or install the ZIP package through **Plugins > Add New > Upload Plugin**.
2. Activate the plugin on the **Plugins** screen. Activation creates and protects `wp-content/failsafe-backups/` and schedules the first automatic backup.
3. Open the **Dashboard** or the **Failsafe** menu to create a backup. The **Backups** screen lists, downloads and deletes backup sets.
4. Open **Settings > Failsafe Backup** to enable or disable automatic backups, choose the frequency and set the retention limit.
5. Use **Tools > Failsafe Restore** to restore the database from a complete `.zip` backup.

The web server user must be able to write to `wp-content/`. For backups, the database user must be allowed to run `SHOW TABLES`, `SHOW CREATE TABLE`, `SELECT` and to read `information_schema`. For a restore, it also needs permission to run `DROP TABLE`, `CREATE TABLE` and `INSERT`, because the dump recreates the tables it contains.

== Frequently Asked Questions ==

= Where are the backup files stored? =

In `wp-content/failsafe-backups/` by default. The directory is created by the plugin and protected with `.htaccess` on Apache. Define `FAILSAFE_BACKUP_DIR` in `wp-config.php` to use another absolute path.

= How many backups are kept? =

Five by default. Change **Backups to keep** under **Settings > Failsafe Backup**: 0 keeps every backup and the maximum is 50. The limit is applied after each successful backup.

= Are old backups overwritten? =

No. Every run creates a new timestamped set. Sets beyond the retention limit are deleted after a successful backup.

= Can visitors download the backup? =

On Apache the `.htaccess` file denies all requests to the directory. On nginx `.htaccess` is ignored: add a server rule such as `location ~* /wp-content/failsafe-backups/ { deny all; }`, or define `FAILSAFE_BACKUP_DIR` in `wp-config.php` to a directory outside the web root. The admin download links require a logged-in user with the backup capability and a valid nonce.

= Does the plugin work on multisite? =

No. It has only been tested on a single site. On a multisite network it dumps every table in the network database and every file in the installation, without any per-site separation.

= Does it restore my site? =

It restores the database from a `.zip` backup. Site files are not restored: the archive is validated, its dump is executed and the extracted files are discarded. To recover files, extract the ZIP over the WordPress directory with the site offline.

= Does restoring delete the backup? =

No. The restore routine does not delete the backup, and the safety backup created before the restore is kept as well. Both count toward the retention limit.

= What happens if a restore fails? =

A failing `SET` statement is logged and skipped. Any other failing statement stops the restore and the screen shows the error. Because the dump recreates tables one by one, the database can be left partially restored. Use the safety backup to try again.

= Why did the backup fail? =

The usual causes are a missing `ZipArchive` extension, a database error, a full disk, or a PHP memory or time limit. The pre-flight checks stop the run before writing when they can predict the problem. The error is written to `wp-content/debug.log` when `WP_DEBUG` and `WP_DEBUG_LOG` are enabled. A manual run also sends a failure e-mail to the administrator address.

= Why did the scheduled backup not run? =

WP-Cron only runs when the site receives a request. A quiet site can be late. Check the next due time under **Settings > Failsafe Backup**; if `DISABLE_WP_CRON` is set, call `wp-cron.php` from a system cron job.

= Does the archive include uploads and other plugins? =

Yes. Every readable file under the WordPress installation is added, except the backup directory, `wp-content/cache` and `wp-content/upgrade`.

= Are backups from version 0.1.0 listed? =

No. Version 0.1.0 used the fixed names `failsafe-backup.sql` and `failsafe-backup.zip`. Those files are not matched by the timestamped names, so they are neither listed nor removed by retention. Download or delete them manually before upgrading if you need them.

= Can I change who is allowed to run backups? =

Yes. Use the `failsafe_backup_capability` filter and return another capability, for example `manage_network_options` or a custom capability.

= Does uninstalling delete my backups? =

By default, no. Uninstall removes the retention setting and keeps the backup files. Backups are deleted only when the `failsafe_delete_backups_on_uninstall` option is enabled before uninstalling. There is no setting for this option in the admin screens yet.

= Is the archive sensitive? =

Yes. The ZIP contains `wp-config.php` and the database dump, including password hashes and personal data. Keep it private.

== Screenshots ==

No screenshot images are bundled with version 0.2.0. The entries below describe the current screens.

1. The **Failsafe Backup** dashboard widget with the latest backup, the count and total size, and the **Backup now** button.
2. The **Backups** screen with the table of sets, download and delete actions, and the total size on disk.
3. The **Failsafe Restore** screen under **Tools**, with the warning, the backup table and the typed confirmation field.
4. The **Failsafe Backup** settings screen with the schedule status, the frequency selector and the retention field.

== Limitations ==

These limits are known and accepted for version 0.2.0:

* **Database size.** The largest database tested is about 2 MB with 12 tables. The SQL dump is built as a single string in memory, so larger databases are untested and may exhaust the PHP `memory_limit` (128 MB in the test environment). The pre-flight check stops a run it expects to exceed the memory limit, but a database in the tens of megabytes is likely to fail.
* **Request time.** Manual and scheduled backups run inside a request. The local sample site takes about 9 seconds. Under Apache the default limit is 30 seconds; larger sites will hit the timeout. There is no background processing.
* **WP-Cron depends on traffic.** Automatic backups only start when WordPress receives a request or when `wp-cron.php` is called by a system cron job. A quiet site can run a backup late.
* **Apache dependency.** Directory protection relies on `.htaccess`. On nginx or another server that ignores `.htaccess`, you must add your own rule or move the directory outside the web root with `FAILSAFE_BACKUP_DIR`.
* **Multisite.** Not supported. See the FAQ above.
* **Restore scope.** Only the database is restored. The site files in the archive are not written to the installation. A restore that fails halfway can leave the database partially restored.
* **Restore input.** Only backups with a non-empty `.zip` archive can be restored from the screen. A `.sql` file on its own cannot be restored there, and extraction needs a temporary directory outside the web root.
* **ZipArchive.** The PHP `zip` extension is required. Without it, backups and restores fail with an error.
* **Database objects.** Views, triggers, stored procedures and events are not included in the dump.
* **Retention and incomplete sets.** Retention only deletes complete sets; a set with one missing file stays until it is deleted by hand.
* **Scheduled lock.** The lock serializes scheduled runs only. A manual backup started while a scheduled one is running is not blocked.
* **Sensitive archive.** The ZIP contains `wp-config.php` and the database dump, including password hashes and personal data. Keep it private.

== Roadmap ==

Multisite support, restoring site files, background processing for large sites and dumping database objects such as views and triggers are not part of version 0.2.0. No release date is promised.

== Changelog ==

= 0.2.0 =

* Timestamped backup sets (`failsafe-YYYYMMDD-HHMMSS`) replace the fixed file names of 0.1.0; older backups are no longer overwritten.
* New top-level **Failsafe** screen that lists date, files and size, downloads the `.zip` or `.sql` file and deletes both files of a set.
* Download through `admin-post.php` with a nonce and a path rebuilt from the validated backup name.
* Delete through a POST request with a nonce tied to the backup set.
* Dashboard widget with the latest backup, the number of sets and the total size on disk.
* Retention limit for complete sets, default 5 (0 keeps every backup, maximum 50).
* Atomic SQL write: the dump is written to a `.part` file and renamed into place; a failed archive is removed.
* Database restore under **Tools > Failsafe Restore**, with typed confirmation, a full safety backup before the restore, archive entry validation and a notice that site files are not changed.
* Scheduled backups (hourly, twice daily, daily and weekly) with a run lock, stale-lock takeover, and last-run and last-result status.
* Settings screen for automatic backups, frequency and retention.
* Pre-flight checks for memory, disk space and maximum execution time before the dump starts.
* Status e-mail for manual runs: failures are reported when the administrator address is valid, and successful runs are reported only with `failsafe_email_on_success`.
* Requires PHP 8.0, matching the plugin header.

= 0.1.0 =

* Initial release.
* Dashboard widget with a **Backup now** button, protected by a nonce and a capability check.
* SQL dump with consistent snapshot, `DROP TABLE IF EXISTS`, `CREATE TABLE` and `INSERT` statements.
* ZIP archive with the SQL dump and all site files, excluding the backup directory, cache and upgrade directories.
* Backup directory created on activation and protected with `.htaccess` and `index.php`.
* Admin notice with the size of the dump and of the archive, plus optional debug logging.
* Clean uninstall: the retention setting is removed; backup files are kept unless explicitly opted in to deletion.

== Upgrade Notice ==

= 0.2.0 =

Backups now use timestamped names and can be listed, downloaded, deleted, restored and scheduled. Fixed-name files from 0.1.0 are not listed or removed by the plugin. Automatic daily backups are scheduled after the update unless you disable them under Settings > Failsafe Backup. Requires PHP 8.0.
