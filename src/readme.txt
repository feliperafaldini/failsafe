=== Failsafe Backup ===
Contributors: felipealbino
Tags: backup, database, files, zip, export
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creates a ZIP archive with the site files and a SQL dump of the database, stored in a protected directory.

== Description ==

Failsafe Backup adds a widget to the WordPress dashboard with a single **Backup now** button. One click produces two files in `wp-content/failsafe-backups/`:

* `failsafe-backup.sql` — a SQL dump of every table in the site database.
* `failsafe-backup.zip` — the same SQL dump plus every readable file in the WordPress installation.

The archive is meant as a manual safety copy before an update or a migration. It is not a replacement for a full hosting-level backup.

**The database dump**

* Starts with the site URL, the UTC date, `SET SESSION sql_mode = ''` and `SET NAMES` with the database charset and collation.
* Disables foreign key checks and opens a consistent snapshot before reading the tables.
* Writes `DROP TABLE IF EXISTS` and `CREATE TABLE` for every table, followed by one `INSERT` per row.
* Ends with `COMMIT` and re-enables foreign key checks.
* Checks `$wpdb->last_error` after every query, so a missing table or a failed read stops the backup instead of producing a partial file.
* Includes every table in the current database, including tables created by other plugins.
* Does not include views, triggers, stored procedures or events.

**The ZIP archive**

* Contains the SQL dump at the archive root and the site files under their original relative paths.
* Excludes the backup directory itself, `wp-content/cache` and `wp-content/upgrade`.
* Includes `wp-config.php`, so treat the archive as sensitive data.

**The backup directory**

The plugin creates `wp-content/failsafe-backups/` on activation and on every admin request if it is missing. The directory receives an `.htaccess` file that denies direct access and an empty `index.php`. The location can be changed by defining `FAILSAFE_BACKUP_DIR` in `wp-config.php` before the plugin loads.

**Who can run a backup**

Users with the `manage_options` capability by default. The capability can be changed with the `failsafe_backup_capability` filter.

**Uninstall**

Deactivation leaves the backups untouched. Uninstall removes the plugin options. Backup files are kept unless the site owner has enabled the `failsafe_delete_backups_on_uninstall` option; there is no setting for this in the admin screens yet.

== Installation ==

1. Upload the `failsafe` directory to `/wp-content/plugins/`, or install the ZIP package through **Plugins > Add New > Upload Plugin**.
2. Activate the plugin on the **Plugins** screen. Activation creates and protects `wp-content/failsafe-backups/`.
3. Open the **Dashboard** and find the **Failsafe Backup** widget.
4. Click **Backup now** and wait for the request to finish. The success notice shows the size of the SQL dump and of the archive.

The web server user must be able to write to `wp-content/`. The database user must be allowed to run `SHOW TABLES`, `SHOW CREATE TABLE` and `SELECT` on the site database.

== Frequently Asked Questions ==

= Where are the backup files stored? =

In `wp-content/failsafe-backups/` by default. The directory is created by the plugin and protected with `.htaccess` on Apache.

= What happens to the previous backup? =

Both files have fixed names and are overwritten on every run. Only the most recent backup is kept. A screen to list, download and delete older backups is not available yet.

= Can visitors download the backup? =

On Apache the `.htaccess` file denies all requests to the directory. On nginx `.htaccess` is ignored: add a server rule such as `location ~* /wp-content/failsafe-backups/ { deny all; }`, or define `FAILSAFE_BACKUP_DIR` in `wp-config.php` to a directory outside the web root.

= Does the plugin work on multisite? =

No. It has only been tested on a single site. On a multisite network it dumps every table in the network database and every file in the installation, without any per-site separation.

= Does it restore my site? =

No. Version 0.1.0 only creates backups. To restore, import the `.sql` file with the database tool of your host and extract the `.zip` file over the WordPress directory.

= Why did the backup fail? =

The usual causes are a missing `ZipArchive` extension, a database error, a full disk, or a PHP memory or time limit. The error is written to `wp-content/debug.log` when `WP_DEBUG` and `WP_DEBUG_LOG` are enabled. The admin screen shows a generic failure message with an HTTP 500 status.

= Does the archive include uploads and other plugins? =

Yes. Every readable file under the WordPress installation is added, except the backup directory, `wp-content/cache` and `wp-content/upgrade`.

= Can I change who is allowed to run backups? =

Yes. Use the `failsafe_backup_capability` filter and return another capability, for example `manage_network_options` or a custom capability.

= Does uninstalling delete my backups? =

By default, no. Uninstall removes the plugin options and keeps the backup files. Backups are deleted only when the `failsafe_delete_backups_on_uninstall` option is enabled before uninstalling.

== Screenshots ==

No screenshot images are bundled with version 0.1.0. The entries below describe the current screens.

1. The **Failsafe Backup** dashboard widget with its description and the **Backup now** button.
2. The success notice on the dashboard after a backup, showing the size of the SQL dump and of the ZIP archive.

== Limitations ==

These limits are known and accepted for version 0.1.0:

* **Database size.** The largest database tested is about 2 MB with 12 tables. The SQL dump is built as a single string in memory, so larger databases are untested and may exhaust the PHP `memory_limit` (128 MB in the test environment). A database in the tens of megabytes is likely to fail.
* **Request time.** The backup runs inside the admin request. The local sample site takes about 9 seconds. Under Apache the default limit is 30 seconds; larger sites will hit the timeout. There is no background processing yet.
* **Apache dependency.** Directory protection relies on `.htaccess`. On nginx or another server that ignores `.htaccess`, you must add your own rule or move the directory outside the web root with `FAILSAFE_BACKUP_DIR`.
* **Multisite.** Not supported. See the FAQ above.
* **ZipArchive.** The PHP `zip` extension is required. Without it the backup fails with an error.
* **Database objects.** Views, triggers, stored procedures and events are not included in the dump.
* **Restore, scheduling and backup management.** Not available in 0.1.0. See the roadmap below.
* **Sensitive archive.** The ZIP contains `wp-config.php` and the database dump, including password hashes and personal data. Keep it private.

== Roadmap ==

Restoring a backup, scheduled backups and an admin screen to list, download and delete backups are in development. They are not part of version 0.1.0 and no release date is promised.

== Changelog ==

= 0.1.0 =

* Initial release.
* Dashboard widget with a **Backup now** button, protected by a nonce and a capability check.
* SQL dump with consistent snapshot, `DROP TABLE IF EXISTS`, `CREATE TABLE` and `INSERT` statements.
* ZIP archive with the SQL dump and all site files, excluding the backup directory, cache and upgrade directories.
* Backup directory created on activation and protected with `.htaccess` and `index.php`.
* Admin notice with the size of the dump and of the archive, plus optional debug logging.
* Clean uninstall: options are removed; backup files are kept unless explicitly opted in to deletion.

== Upgrade Notice ==

= 0.1.0 =

Initial release. Nothing to upgrade.
