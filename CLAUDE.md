# RMD Migrate to Localdev – Projektregeln

WordPress-Plugin von reichelt media.design, läuft auf **Live-Seiten** (z. B. bei Strato) und erzeugt dort eine Kopie für die lokale Entwicklung mit XAMPP: Zip aller Dateien plus Datenbank-Dump zum Herunterladen. Gegenstück zu `rmd-migrate-from-localdev` (gleicher Aufbau, umgekehrte Richtung). Repo: `github.com/reicheltmediadesign/wordpress-rmd-migrate-to-localdev`, Lizenz GPL-2.0-or-later, Autor Philipp Reichelt. Kein npm/Build-Schritt.

## Aufbau

- `rmd-migrate-to-localdev.php` Bootstrap (muss PHP-7-parsebar bleiben), `includes/` PSR-4 `RMD\MigrateToLocaldev\`.
- `includes/Domain/` ist **WordPress-frei** und wird mit plain PHPUnit getestet: `SerializedReplacer`, `PathFilter`, `Sql` (unverändert aus `rmd-migrate-from-localdev` übernommen – Fixes dort auch hier nachziehen und umgekehrt), `ReplacementPlan` (Live-URL inkl. www-Variante → lokale URL, root-relative Links nur für `/wp-content/`, `/wp-includes/`, `/wp-admin/`, mehrere Schreibweisen des Serverpfads, `has_leftover()` für den Bericht), `Target` (einzige Stelle für die Datenform des lokalen Ziels), `Htaccess`, `WpConfig`, `ZipWriter` (eigener Zip-Writer, nur anhängend, Zip64), `ByteRange`.
- `includes/Export/`: `Job` (Zustand in Option `rmd_mtl_job`, Phasen database → scan → zip → finalize → done, je Request ~12 s), `DatabaseExport`, `FileExport`, `Finisher`, `Report`, `Exports` (Exportordner, Aufräumen), `Download` (Stream mit Range).
- Fortsetzen: Ausgabedateien werden auf die im Zustand gespeicherte Länge gekürzt (`Exports::open_append`). Beim Zip gilt das für `files.zip` **und** die Nebendatei `.central` (Central-Directory-Einträge); `ZipArchive` wird bewusst nicht benutzt, weil es bei jedem `close()` das ganze Archiv neu schreibt.
- `Rest\ExportController` treibt den Job an, `Admin\Page` (Werkzeuge → Migrate to localdev) mit `assets/js/admin.js` (Vanilla, `wp-api-fetch`). Das Formular wird per REST abgeschickt und als Option `rmd_mtl_target` gemerkt.
- Zugriff: `manage_options`; jede Schreibaktion und jeder Download mit Nonce. **Kein** `is_local()`-Gate wie im Schwesterplugin – dafür gilt:
  - Exportordner `wp-content/rmd-migrate-to-localdev-<Zufallsschlüssel>` mit `.htaccess`-Sperre; Downloads nur über `admin-post.php`.
  - Exporte werden nach 24 h gelöscht (`Exports::LIFETIME`, Cron `rmd_mtl_cleanup` und beim Öffnen der Seite) und bei Deaktivierung komplett.
- Nie in der Kopie: Exportordner, dieses Plugin; `wp-config.php` und `.htaccess` werden neu erzeugt (Live-`.htaccess` als `.htaccess.live`). Im Dump fehlen Transients, `rmd_mtl_*`-Optionen, und das Plugin wird aus `active_plugins` entfernt.

## Code-Regeln

- PHP 8.1+, WP 6.8+. WordPress-Coding-Standards (`phpcs.xml.dist`), Methoden snake_case, Präfix `rmd_mtl_` / `RMD_MTL_` / Namespace `RMD\MigrateToLocaldev`, Textdomain `rmd-migrate-to-localdev`.
- Quellstrings Englisch, Übersetzung in `languages/rmd-migrate-to-localdev-de_DE.po` von Hand nachziehen (`bash bin/make-i18n.sh` braucht WP-CLI: lokal `C:\xampp\php\wp.bat`, im Release-Workflow vorhanden).
- Neue Schreibweisen oder Sonderfälle beim Ersetzen immer mit Tests in `tests/Unit/ReplacementPlanTest.php` bzw. `SerializedReplacerTest.php`; Änderungen am Zip-Format mit `ZipWriterTest.php` (liest mit `ZipArchive` zurück).
- Version an drei Stellen: Plugin-Header, `RMD_MTL_VERSION`, `readme.txt` (Stable tag). `bash bin/check-version.sh` prüft das.

## Build und Prüfung

```powershell
composer phpcs         # PHPCS
composer test          # PHPUnit (Domain)
bash bin/check-version.sh
bash bin/build-zip.sh  # dist/rmd-migrate-to-localdev.zip (Git Bash: zip, composer nötig)
```

Composer liegt lokal unter `C:\xampp\php\composer.bat`. Lokal testen: `.\bin\link-local.ps1 -WordPressPath "C:\xampp\htdocs\testwordpress"` legt eine Junction in `wp-content\plugins` an; das Plugin läuft auch auf lokalen Seiten (Quelle ist dann eben `localhost/<seite>`). Für Kundenseiten das Release-Zip installieren und nach dem Export wieder deaktivieren.

## Git und Release

- Committen nur auf Anfrage, auf Feature-Branches; nie direkt auf `main`.
- Release: Versionen an allen Stellen erhöhen, `CHANGELOG.md` und `readme.txt` (Changelog) ergänzen, committen, Tag `vX.Y.Z` pushen. Der Workflow lintet, testet, baut Übersetzungen, erzeugt `rmd-migrate-to-localdev.zip` und `update.json` und legt ein **Draft-Release** an. Beide Assets müssen am Release bleiben (Update-Check liest `releases/latest/download/update.json`).
- Den vom Workflow angelegten Entwurf bearbeiten (Releases → Entwurf → Stift), nicht neu anlegen.
- Bei jedem Release Titel `vX.Y.Z – <Kernänderung>` und Release Notes (Englisch, Markdown in Codeblock) mit ausgeben; Grundlage `git log <letzter Tag>..HEAD`.
- Topics fürs Repo: `wordpress`, `wordpress-plugin`, `migration`, `search-replace`, `xampp`.
- Nicht in `new_project.ps1` aufnehmen (wird nur bei Bedarf auf einer Live-Seite installiert) und kein Doku-Block in `rmd-docs` (rein administratives Werkzeug).

## README

Nutzerorientiert (Features, Usage, Settings, Privacy, License). Kein Development-Abschnitt in der README; Build/Release steht hier.
