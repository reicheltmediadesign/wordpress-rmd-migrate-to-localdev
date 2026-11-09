# RMD Migrate to Localdev

WordPress plugin that creates a copy of a live site for local development with XAMPP. It packs all files into a zip archive and writes a database dump in which every address and path of the live site is replaced by that of the local copy, safe for serialized data. You download both, unpack the archive into `htdocs` and import the dump in phpMyAdmin. The live site is not changed. Built by [reichelt media.design](https://reicheltmedia.design).

It is the counterpart of [RMD Migrate from Localdev](https://github.com/reicheltmediadesign/wordpress-rmd-migrate-from-localdev), which prepares a local site for upload.

## Features

- **Serialization-safe search and replace.** Serialized PHP values (widgets, theme and plugin settings) are parsed, rewritten and re-serialized with correct lengths. Objects are never instantiated, so classes that are not loaded survive unchanged. Serialized data nested inside strings is handled as well.
- **Every spelling of the live address:** `http://` and `https://`, with and without `www.`, protocol-relative (`//example.com`), JSON-escaped (`https:\/\/example.com`) and URL-encoded. E-mail addresses and the bare domain in text stay as they are.
- **Root-relative links** to WordPress folders (`src="/wp-content/…"`, `url(/wp-includes/…)`) get the local folder in front, because the copy lives in a subfolder of `localhost`.
- **Server paths** stored by plugins (`/mnt/web123/…/htdocs/site`) are replaced by the local path (`C:/xampp/htdocs/<folder>`).
- **The live database is only read.** Replacements happen while writing the dump.
- **One zip archive** with a top-level folder named like your local folder. It is written in short steps and can be continued after an interruption; archives above 4 GB or with more than 65,535 files work (Zip64).
- **Ready to run:** the archive contains a `wp-config.php` with your local database access, new security keys and `WP_ENVIRONMENT_TYPE` `local`, and a fresh `.htaccess` with the right `RewriteBase`. `FORCE_SSL_ADMIN` and `WP_CACHE` are switched off, the cache drop-ins are left out.
- **The dump creates the database** (`CREATE DATABASE IF NOT EXISTS`), so it can be imported in phpMyAdmin without preparing anything. Collations of MySQL 8 and new MariaDB versions are replaced by ones every XAMPP version knows.
- **Complete by default,** optionally without the media library for a quick, small copy, or database only.
- **Leaner dump:** transients, this plugin's own data and optionally revisions and spam are left out; tables like Yoast's indexables are exported empty because the plugin rebuilds them.
- **Search engines discouraged** in the copy, in case it is ever reachable from outside.
- **Report** (`MIGRATION.txt`) with the steps for XAMPP, rows and changed values per table, and every value that still refers to the live site.
- **Safe on a public server:** exports are stored in a folder with a random name that is closed to the web, downloads only work for logged-in administrators, and exports are deleted automatically after 24 hours and when the plugin is deactivated.

## Requirements

WordPress 6.8 or newer, PHP 8.1 or newer with the zlib extension, a single site (no multisite). The site needs free disk space for the archive, roughly the size of the installation.

## Installation

1. Download `rmd-migrate-to-localdev.zip` from the latest [release](https://github.com/reicheltmediadesign/wordpress-rmd-migrate-to-localdev/releases). **Do not use the “Source code” downloads**, they lack the update library.
2. On the **live** site go to Plugins → Add New → Upload Plugin, choose the zip and activate it.
3. Open **Tools → Migrate to localdev**.

The plugin is not part of its own exports and is removed from the list of active plugins in the dump. Deactivate it on the live site when you are done; that also deletes all exports.

## Usage

1. Enter the **folder in htdocs** the copy will live in, e.g. `example.com`, and the local database access. The database is named like the folder unless you enter another name.
2. Click **Create export**. The progress bar shows the current table or file count. If a step fails (e.g. a time limit of the host), click **Continue**.
3. Download the files:

   | Download | Use |
   | --- | --- |
   | Files (zip) | Unpack into `C:\xampp\htdocs`. It creates the folder with the complete site, `wp-config.php` and `.htaccess`. |
   | Database | Import in phpMyAdmin (`http://localhost/phpmyadmin` → Import) without selecting a database first. Existing tables with the same names are replaced. |
   | MIGRATION.txt | The steps, statistics and remaining references to the live site. |

4. Open `http://localhost/<folder>/wp-admin/`, log in with the users of the live site and save **Settings → Permalinks** once.

Large downloads can be resumed by the browser. If a download through PHP does not work on your host, fetch the files via SFTP from the export folder in `wp-content` (its name starts with `rmd-migrate-to-localdev-`).

All plugins are active in the copy as on the live site. Cache and security plugins may need to be deactivated locally, and plugins that send e-mails or orders to third parties keep doing so from the copy.

## Settings

| Setting | Default | Description |
| --- | --- | --- |
| Folder in htdocs | host name of the site | Name of the local folder. Top-level folder of the archive and last part of the local address. |
| Database name | same as the folder | Name of the local database, written into `wp-config.php` and created by the dump. |
| Database user | `root` | User of the local MySQL server for `wp-config.php`. |
| Database password | empty | Password of that user for `wp-config.php`. Saved on the site for the next export. |
| htdocs path | `C:/xampp/htdocs` | Server paths in the database are replaced by this path plus the folder. |
| Local address | `http://localhost` | Address of the local web server without the folder, e.g. `http://localhost:8080`. |
| Files | on | Pack the complete WordPress folder. Off = database only. |
| Uploads | on | Include the media library. Off = much smaller archive, images are missing locally. |
| Leave out | see below | Exclusion rules, one per line. |
| Tables without data | `actionscheduler_logs`, `yoast_indexable`, `yoast_indexable_hierarchy`, `yoast_seo_links` | Tables (without prefix) that are created empty. |
| Relative links | on | Put the local folder in front of links like `/wp-content/…`. |
| Revisions | off | Leave out post revisions. |
| Spam | on | Leave out spam and trashed comments. |
| Search engines | on | Set "Discourage search engines from indexing this site" in the copy. |

### Exclusion rules

The syntax follows `.gitignore`: `name` matches a file or folder at any depth, `/name` only directly in the WordPress folder, a trailing `/` only folders, `*` and `?` stay within a path segment, `**` spans segments. Matching is case-insensitive. Defaults:

```
.git/
.svn/
node_modules/
/wp-content/cache/
/wp-content/upgrade/
/wp-content/upgrade-temp-backup/
/wp-content/advanced-cache.php
/wp-content/object-cache.php
/wp-content/ai1wm-backups/
/wp-content/updraft/
/wp-content/backups-dup-lite/
/wp-content/uploads/backwpup-*/
/wp-content/wflogs/
*.log
error_log
.DS_Store
Thumbs.db
```

Always left out: the export folder and this plugin. `wp-config.php` and `.htaccess` are replaced by versions for the local copy; the `.htaccess` of the live site is included as `.htaccess.live` for reference, because its redirects and server settings usually do not work with XAMPP. Linked folders (symlinks) are skipped.

## Developer hooks

- REST (administrators only): `GET|POST|DELETE /wp-json/rmd-migrate-to-localdev/v1/export`, `POST …/export/step`.

## Privacy

The plugin makes no external requests, except for checking GitHub for plugin updates from the WordPress admin. Exports contain the complete database including user accounts and password hashes, and the `wp-config.php` of the copy keeps other secrets of the live site (e.g. API keys defined there). Exports are deleted from the server after 24 hours; keep the downloaded files private.

## License

GPL-2.0-or-later. Uses [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
