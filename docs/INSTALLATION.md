# Installing Bolum locally

## Requirements

- PHP **8.4 or later within PHP 8.x**, with the extensions required by `composer.lock`. Use `composer check-platform-reqs` after installing to check your terminal's PHP.
- Composer 2 and internet access for the first dependency download.
- PDO SQLite for the default database. No separate database server is required.
- For MySQL: PDO MySQL, MySQL **8.0 or 8.4**, InnoDB, and an InnoDB page size of **at least 16 KB**. MariaDB is not part of the tested setup below.

The dashboard uses Blade and static assets. Node/npm are needed for browser development tests, not to open the application.

## First start: Bash or Windows PowerShell

Open a terminal in the project directory:

```text
php -v
composer setup
composer check-platform-reqs
php artisan serve
```

Visit **http://127.0.0.1:8000**. Open a second terminal in the same directory:

```text
php artisan queue:work --tries=3 --timeout=60
```

The web server accepts requests; the worker generates queued predictions. Without a worker, requests remain pending. Stop either process with Ctrl+C.

Sign in as `admin@bolum.test` or `member@bolum.test`, with password `password123`. These are local sample accounts. Registration is also available.

`composer setup` installs versions from `composer.lock`, copies `.env.example` only if `.env` is absent, creates a missing SQLite database file, generates an absent application key, then runs migrations and local seeders. It does **not** run `migrate:fresh`, overwrite an existing `.env`, or regenerate an existing key. Seeders reuse sample records and preserve existing credit activity; they may restore the sample accounts' intended roles. Setup refuses production environments and cached configuration.

For an existing installation, normally use `composer install`, `php artisan migrate`, and restart your queue worker. Do not replace your `.env` or generate a new key as a routine update.

### Windows / Herd

Use PHP 8.4+ for this project. The PHP executable in your terminal must also satisfy that requirement; a web-server PHP selection alone does not establish which executable PowerShell uses.

```powershell
Get-Command php
php -v
php --ini
php -m
```

Check for `pdo_sqlite` for SQLite or `pdo_mysql` for MySQL. If Composer says an extension is missing, enable it in the PHP configuration reported by `php --ini`, then reopen the terminal if your PATH changed. The setup commands above do not depend on Unix `touch`, `cp`, or shell environment-variable syntax.

### Manual setup

These commands also work in Bash and PowerShell:

```text
composer install
php -r "file_exists('.env') || copy('.env.example', '.env');"
php artisan bolum:setup
```

If configuration was previously cached, run `php artisan config:clear` first. Fix any database connection error before retrying setup. Do not use `--ignore-platform-reqs` to hide a PHP or extension mismatch.

## Using MySQL

Create an empty local database, for example `bolum`, on a compatible MySQL server. Copy `.env.example` to `.env` if needed, then edit the existing file:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bolum
DB_USERNAME=your_local_database_user
DB_PASSWORD=your_local_database_password
```

Use your own credentials; the database must already exist and the user must have schema-creation privileges on it. Remove any old SQLite `DB_DATABASE` path when switching. Then run:

```text
php artisan config:clear
php artisan bolum:setup
```

Switching connections does not copy your existing SQLite users or predictions into MySQL. Keep the SQLite file if you want to return to that data.

In TablePlus or your MySQL client, inspect the server with these read-only queries:

```sql
SELECT VERSION(), @@innodb_page_size, @@innodb_default_row_format;

SELECT TABLE_NAME, ENGINE, ROW_FORMAT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;
```

## Troubleshooting an index-length migration error

MySQL errors such as **1071: Specified key was too long** or **1709: Index column size too large** indicate an index exceeds a storage-engine limit. Read the full message and inspect the server and affected table before selecting a fix.

With `utf8mb4`, a character can occupy four bytes: a 255-character indexed email can require 1,020 bytes. InnoDB `COMPACT`/`REDUNDANT` row formats have a 767-byte index-prefix limit, while `DYNAMIC` supports 3,072 bytes at a 16 KB page size. Smaller pages reduce that limit. See the [MySQL index documentation](https://dev.mysql.com/doc/refman/8.0/en/create-index.html) and [InnoDB limits](https://dev.mysql.com/doc/refman/en/innodb-limits.html).

Bolum explicitly creates new MySQL tables using `InnoDB ROW_FORMAT=DYNAMIC` in `config/database.php`. This avoids inheriting a server's legacy `COMPACT` default. The local setup command also checks page size before running MySQL migrations. Normal `php artisan migrate` still uses the configured row format, but does not run that setup preflight.

Changing every string length to 191 is not a complete solution for this schema: Bolum also has composite indexes containing multiple strings. Shortening columns can change allowed data lengths and validation expectations. Use the documented database configuration instead of adding a blanket length override.

**Existing tables are not automatically converted.** If a migration already failed, MySQL may have retained some tables because schema changes are not generally rolled back as one transaction. Back up any useful data, inspect `php artisan migrate:status` and table definitions, and plan a targeted repair. Correct the row format of affected tables where appropriate; reconcile partially applied schema changes before rerunning migrations. Do not mark a migration completed without verifying all its changes, or run `migrate:fresh` against a database containing data you want to keep.

## Sample data, real football data, and scheduled work

A fresh install runs without API credentials using clearly labelled synthetic sample predictions. Real fixtures and club crests require the optional football-data.org token; follow [the README's synchronization steps](../README.md#real-fixture-synchronization). A real fixture alone does not make a sample provider's forecast a real-data prediction.

If sample fixtures have passed, `php artisan demo:refresh` prepares upcoming sample fixtures while preserving history. Use `php artisan schedule:work` in another terminal for local scheduled tasks. Enable automatic football synchronization only after configuring its token.

## Verification

```text
composer validate --strict
composer check-platform-reqs
php artisan test
```

The default test configuration uses in-memory SQLite and fake external HTTP responses. When deliberately testing MySQL, use a **separate disposable database**: the test suite recreates tables. Never point it at your working database.

CI includes SQLite/MySQL application tests and browser tests. The installation job separately checks fresh setup and repeat setup on Ubuntu and Windows. A workflow definition is not evidence that a remote run has passed; check the repository's Actions results after pushing.

Local verification on 2026-09-13 used Ubuntu, PHP 8.4.24, SQLite, and an isolated MySQL 8.0.46 server with 16 KB pages and a deliberately configured COMPACT default. All nine migrations and seeders passed; all 20 MySQL tables were DYNAMIC. The 65 application tests passed on both database engines. A clean source copy with separately installed locked dependencies served the homepage, authenticated a sample user, and completed a queued prediction. Repeat setup preserved its environment/key, credit balance, ledger and prediction. Windows execution and the MySQL 8.4 CI run still require their remote workflow results.
