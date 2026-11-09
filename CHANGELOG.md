# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.1.0] – 2026-11-09

### Added

- Export of a live site for local development with XAMPP: folder in `htdocs`, database name, database user and password, `htdocs` path and local address.
- Database dump with serialization-safe search and replace of every spelling of the live address (with and without `www.`), root-relative links to WordPress folders and server paths; the live database is only read. The dump creates the local database.
- Zip archive of the complete site, written by appending in short steps (Zip64 for large sites), optionally without the media library; `.gitignore`-style exclusion rules.
- `wp-config.php` with the local database access, new security keys and `WP_ENVIRONMENT_TYPE` `local`, and a fresh `.htaccess` for the local folder; the `.htaccess` of the live site is kept as `.htaccess.live`.
- `MIGRATION.txt` report with the steps for XAMPP, statistics and remaining references to the live site.
- Step-by-step export with progress bar, continue and cancel.
- Downloads for administrators only, resumable; exports are closed to the web and deleted automatically after 24 hours and on deactivation.
- Automatic updates from GitHub releases.
