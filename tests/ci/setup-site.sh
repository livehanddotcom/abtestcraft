#!/usr/bin/env bash
# Builds a fresh Craft site with ABTestCraft installed, for the integration tests.
# Used by GitHub Actions (.github/workflows/checks.yml); it can also run
# locally against an empty database.
#
#   tests/ci/setup-site.sh <site-dir>
#
# Environment:
#   DB_DRIVER      pgsql or mysql
#   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD
#   CRAFT_VERSION  optional Craft version to install (e.g. 5.0.0); default is the newest
#   UPGRADE_FROM   optional ABTestCraft release to start from (e.g. 1.0.0): installs it
#                  from Packagist, records visitor traffic with it, upgrades to this
#                  code and checks the data survived (tests/ci/upgrade-check.php)
#   SITE_URL       where the site will be served; default http://127.0.0.1:8080/
#
# The plugin is copied to <site-dir>/local/craft-abtestcraft (where the test
# bootstrap expects it, as in local development). To run the tests, serve the
# site at SITE_URL (php -S 127.0.0.1:8080 -t <site-dir>/web) and run the site's
# Codeception from the plugin copy: ../../vendor/bin/codecept run integration
set -euo pipefail

SITE="${1:?Usage: setup-site.sh <site-dir>}"
PLUGIN_SRC="$(cd "$(dirname "$0")/../.." && pwd)"
: "${DB_DRIVER:?}" "${DB_HOST:?}" "${DB_PORT:?}" "${DB_NAME:?}" "${DB_USER:?}" "${DB_PASSWORD:?}"
SITE_URL="${SITE_URL:-http://127.0.0.1:8080/}"

if [ -e "$SITE" ]; then
    echo "$SITE already exists" >&2
    exit 1
fi

echo "::group::Create the Craft project"
composer create-project craftcms/craft "$SITE" --no-install --no-scripts --no-interaction --quiet
cd "$SITE"
SITE="$(pwd)"
# The skeleton's composer.json is for developing the skeleton itself
mv composer.json.default composer.json
composer config --unset scripts
composer remove --dev --no-update --no-interaction craftcms/generator yiisoft/yii2-shell
# Keep the general config minimal, so it works on every supported Craft version
cat > config/general.php <<'PHP'
<?php

use craft\config\GeneralConfig;

return GeneralConfig::create()
    ->devMode(true)
    ->omitScriptNameInUrls(true)
    ->aliases(['@webroot' => dirname(__DIR__) . '/web']);
PHP
# The template for the seeded pages (the front-end tests read the title)
cat > templates/_page.twig <<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ entry.title }}</title>
    {{ head() }}
</head>
<body>
    {{ beginBody() }}
    <h1 id="page-title">{{ entry.title }}</h1>
    <p><a href="tel:+15555550100">Call us</a></p>
    {{ endBody() }}
</body>
</html>
TWIG
echo "::endgroup::"

echo "::group::Add the plugin"
mkdir -p local/craft-abtestcraft
tar -C "$PLUGIN_SRC" --exclude=./vendor --exclude=./.git --exclude=./tests/_output \
    --exclude=./tests/_craft/storage --exclude=./tests/.env -cf - . | tar -C local/craft-abtestcraft -xf -
if [ -n "${CRAFT_VERSION:-}" ]; then
    # Old Craft releases have security advisories, which Composer refuses by
    # default. Fine for a throwaway test site.
    composer config audit.block-insecure false
fi
# Installs livehand/abtestcraft at $1 (this code when it's "*"), plus the Craft version asked for
require_plugin() {
    local packages=("livehand/abtestcraft:$1")
    if [ -n "${CRAFT_VERSION:-}" ]; then packages+=("craftcms/cms:${CRAFT_VERSION}"); fi
    composer require "${packages[@]}" --with-all-dependencies --no-interaction --no-progress
}
use_this_code() {
    # A path repository comes before Packagist, so this code wins over the release
    composer config repositories.abtestcraft path local/craft-abtestcraft
    require_plugin "*"
}
if [ -n "${UPGRADE_FROM:-}" ]; then
    require_plugin "$UPGRADE_FROM"
else
    use_this_code
fi
echo "::endgroup::"

echo "::group::Install Craft and the plugin"
SECURITY_KEY="$(php -r 'echo bin2hex(random_bytes(24));')"
if [ "$DB_DRIVER" = "pgsql" ]; then DB_SCHEMA=public; else DB_SCHEMA=; fi
cat > .env <<ENV
CRAFT_APP_ID=abtestcraft-tests
CRAFT_ENVIRONMENT=dev
CRAFT_SECURITY_KEY=${SECURITY_KEY}
CRAFT_DB_DRIVER=${DB_DRIVER}
CRAFT_DB_SERVER=${DB_HOST}
CRAFT_DB_PORT=${DB_PORT}
CRAFT_DB_DATABASE=${DB_NAME}
CRAFT_DB_USER=${DB_USER}
CRAFT_DB_PASSWORD=${DB_PASSWORD}
CRAFT_DB_SCHEMA=${DB_SCHEMA}
CRAFT_DB_TABLE_PREFIX=
ENV
# Variables already in the environment win over .env (ddev sets its own CRAFT_DB_*),
# so export these too
set -a
# shellcheck disable=SC1091
. ./.env
set +a
php craft install --interactive=0 --username=admin --email=admin@example.com \
    --password="$(php -r 'echo bin2hex(random_bytes(12));')" \
    --site-name="ABTestCraft Tests" --site-url="$SITE_URL" --language=en-US
php craft plugin/install abtestcraft --interactive=0
php local/craft-abtestcraft/tests/ci/seed.php "$SITE"
composer show | grep -E "^(craftcms/cms|livehand/abtestcraft) "
echo "::endgroup::"

if [ -n "${UPGRADE_FROM:-}" ]; then
    echo "::group::Upgrade from ${UPGRADE_FROM}"
    php local/craft-abtestcraft/tests/ci/upgrade-check.php "$SITE" before
    use_this_code
    php craft up --interactive=0
    php local/craft-abtestcraft/tests/ci/upgrade-check.php "$SITE" after
    composer show | grep -E "^livehand/abtestcraft "
    echo "::endgroup::"
fi

echo "::group::Install the test tools"
# Into the site, not the plugin's own vendor folder: one set of libraries, so
# the Craft version under test is the one that loads
TEST_TOOLS=()
while IFS= read -r tool; do TEST_TOOLS+=("$tool"); done < <(php -r '
    $c = json_decode(file_get_contents("local/craft-abtestcraft/composer.json"), true);
    foreach ($c["require-dev"] as $name => $version) {
        if (str_starts_with($name, "codeception/")) { echo "$name:$version\n"; }
    }')
composer require --dev "${TEST_TOOLS[@]}" --with-all-dependencies --no-interaction --no-progress
cd local/craft-abtestcraft
# Craft's storage folder for the tests (not committed)
mkdir -p tests/_craft/storage/runtime tests/_output
cat > tests/.env <<ENV
CRAFT_DB_DSN="${DB_DRIVER}:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME}"
CRAFT_DB_USER=${DB_USER}
CRAFT_DB_PASSWORD=${DB_PASSWORD}
CRAFT_DB_SCHEMA=${DB_SCHEMA}
CRAFT_DB_TABLE_PREFIX=
SECURITY_KEY=${SECURITY_KEY}
DEFAULT_SITE_URL=${SITE_URL}
# A disposable site at this URL: turns on the front-end and console command tests
ABTESTCRAFT_TEST_SITE_URL=${SITE_URL}
ENV
echo "::endgroup::"

echo "Site ready. Serve it at ${SITE_URL} and run the tests from $SITE/local/craft-abtestcraft with ../../vendor/bin/codecept"
