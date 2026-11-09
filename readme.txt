=== RMD Migrate to Localdev ===
Contributors: reicheltmediadesign
Tags: migration, search replace, database export, localhost, xampp
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creates a download of a live site for local development with XAMPP: a zip of all files and a database dump with serialization-safe search and replace.

== Description ==

RMD Migrate to Localdev turns a live WordPress site into a copy for local development with XAMPP. You download a zip archive and a database dump, unpack the archive into htdocs and import the dump in phpMyAdmin.

* Serialization-safe search and replace of every spelling of the live address (http/https, with and without www, protocol-relative, JSON-escaped, URL-encoded, relative links) and of server paths.
* The live database is only read; replacements happen while writing the dump.
* One zip archive with the complete site, a wp-config.php with the local database access and a fresh .htaccess.
* The dump creates the local database itself.
* Complete by default, optionally without the media library or database only.
* Report with the steps for XAMPP and every value that still refers to the live site.
* Runs in short steps with a progress bar and can be continued after an interruption.
* Exports are closed to the web, only administrators can download them, and they are deleted automatically after 24 hours.

== Installation ==

1. Upload the plugin zip on the live site under Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to Tools → Migrate to localdev, enter the folder in htdocs and click Create export.
4. Deactivate the plugin when you are done; that also deletes all exports.

== Frequently Asked Questions ==

= Why not a simple SQL search and replace? =

Many settings are stored as serialized PHP data that contains the length of every string. Replacing an address with one of a different length breaks these values. The plugin rewrites them with correct lengths.

= Is the live site changed? =

No. The database is only read. The plugin stores its own settings and writes the export into a protected folder in wp-content, which it deletes again.

= Where is the export stored? =

In a folder in wp-content whose name ends with a random key. It is closed to the web; downloads go through WordPress and need an administrator login.

== Changelog ==

= 0.1.0 =
* Initial release.
