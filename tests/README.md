# Tests

Two Codeception suites, run separately (see `codeception.yml`):

- **unit** (`tests/unit`): no database, a minimal Yii app.
  `vendor/bin/codecept run unit`
- **integration** (`tests/integration`): full Craft, using the database in
  `tests/.env` (copy `tests/.env.example`). The plugin is expected at
  `<site>/local/craft-abtestcraft`. The tests create and delete their own data.
  `vendor/bin/codecept run integration`

## Tests that need a throwaway site

`FrontEndIntegrationTest` makes real HTTP requests to the site, and
`ConsoleCommandsIntegrationTest` runs `./craft` commands, some of which act on
every test in the database (`cleanup`, `run-scheduled`). They only run when
`ABTESTCRAFT_TEST_SITE_URL` is set in `tests/.env`, and otherwise skip. Don't set
it on a site with real tests.

## GitHub Actions

`.github/workflows/checks.yml` runs on every pull request and on main:

- **PHP 8.2 / 8.3 / 8.4**: PHPCS, PHPStan and the unit tests.
- **Integration**: builds a fresh Craft site, serves it, and runs the whole
  integration suite. It fails if any test is skipped. Then it uninstalls and
  reinstalls the plugin. There are six jobs:
  - Postgres, PHP 8.4, newest Craft
  - MySQL, PHP 8.2, Craft 5.0.0 (the oldest supported)
  - Upgrade from 1.0.0 on Postgres, and on MySQL
  - Upgrade from 1.1.0 (Postgres) and from 1.2.0 (MySQL)

The scripts in `tests/ci`:

| File | What it does |
|---|---|
| `setup-site.sh` | Creates a Craft project, installs the plugin from this checkout, seeds pages and writes `tests/.env` |
| `seed.php` | A "Pages" section with the entries the tests use |
| `upgrade-check.php` | Records visitors and conversions with an old release, then after `craft up` checks they all survived and returning visitors keep their version |
| `uninstall-check.php` | Uninstalling removes all of the plugin's tables, and installing again recreates them |

## Running the CI setup locally

Against an empty database (it installs Craft there):

```bash
DB_DRIVER=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_NAME=abtc_ci DB_USER=db DB_PASSWORD=db \
  tests/ci/setup-site.sh /tmp/abtc-site
# Optional: CRAFT_VERSION=5.0.0, UPGRADE_FROM=1.0.0, SITE_URL=...

cd /tmp/abtc-site
php -d opcache.enable_cli=0 -S 127.0.0.1:8080 -t web &
cd local/craft-abtestcraft
../../vendor/bin/codecept run integration
php tests/ci/uninstall-check.php /tmp/abtc-site
```

Codeception runs from the site's `vendor`, not the plugin's, so that only one
copy of Craft is loaded. Inside ddev, run it in the web container: ddev's
`CRAFT_DB_*` variables point at the main site's database, and the setup script
overrides them with its own `.env`.

## When adding a migration

The upgrade jobs run every migration on a 1.0.0 database (and on 1.1.0 and 1.2.0 ones). A migration that
loads tests through the plugin's services or models can fail there, because
the newer columns don't exist yet. Query the table directly and select only the
columns the migration needs (see `m261125_000001_clear_page_cache_for_running_tests`).
