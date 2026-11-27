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
#
# The plugin is copied to <site-dir>/local/craft-abtestcraft (where the test
# bootstrap expects it, as in local development). Run the tests from there,
# with the site's Codeception: ../../vendor/bin/codecept run integration
set -euo pipefail

SITE="${1:?Usage: setup-site.sh <site-dir>}"
PLUGIN_SRC="$(cd "$(dirname "$0")/../.." && pwd)"
: "${DB_DRIVER:?}" "${DB_HOST:?}" "${DB_PORT:?}" "${DB_NAME:?}" "${DB_USER:?}" "${DB_PASSWORD:?}"

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
    ->aliases(['@webroot' => dirname(__DIR__) . '/web']);
PHP
echo "::endgroup::"

echo "::group::Add the plugin"
mkdir -p local/craft-abtestcraft
tar -C "$PLUGIN_SRC" --exclude=./vendor --exclude=./.git --exclude=./tests/_output \
    --exclude=./tests/_craft/storage --exclude=./tests/.env -cf - . | tar -C local/craft-abtestcraft -xf -
composer config repositories.abtestcraft path local/craft-abtestcraft
PACKAGES=("livehand/abtestcraft:*")
if [ -n "${CRAFT_VERSION:-}" ]; then
    PACKAGES+=("craftcms/cms:${CRAFT_VERSION}")
    # Old Craft releases have security advisories, which Composer refuses by
    # default. Fine for a throwaway test site.
    composer config audit.block-insecure false
fi
composer require "${PACKAGES[@]}" --with-all-dependencies --no-interaction --no-progress
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
    --site-name="ABTestCraft Tests" --site-url="http://localhost/" --language=en-US
php craft plugin/install abtestcraft --interactive=0
php local/craft-abtestcraft/tests/ci/seed.php "$SITE"
composer show | grep -E "^(craftcms/cms|livehand/abtestcraft) "
echo "::endgroup::"

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
DEFAULT_SITE_URL=http://localhost/
ENV
echo "::endgroup::"

echo "Site ready. Run the tests from $SITE/local/craft-abtestcraft with ../../vendor/bin/codecept"
