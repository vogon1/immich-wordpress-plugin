#!/bin/bash
#
# Run Plugin Check, with every check enabled, against exactly what ships: the files rsync copies
# according to .distignore. Static checks and runtime checks (which load the plugin and look at
# what it enqueues) both run.
#
# Needs a running wp-env (npm run wp-env:start). Usage: npm run plugin-check
#
# Runtime checks need the checked plugin to be active, and the release copy cannot be active at
# the same time as the development copy (same class names). The development copy is therefore
# deactivated during the run and always reactivated afterwards.

set -e

PLUGIN_SLUG="gallery-for-immich"
CHECK_DIR="${PLUGIN_SLUG}-release-check"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

echo "Copying the release contents to dist/release-check/ ..."
rm -rf dist/release-check
mkdir -p dist/release-check
rsync -a --exclude-from=.distignore ./ "dist/release-check/${PLUGIN_SLUG}/"
find "dist/release-check/${PLUGIN_SLUG}" -type f | sed "s#dist/release-check/${PLUGIN_SLUG}/#  #" | sort

restore() {
    echo "Restoring wp-env ..."
    npx wp-env run cli bash -c "
        wp plugin deactivate ${CHECK_DIR} --quiet 2>/dev/null
        rm -rf wp-content/plugins/${CHECK_DIR}
        wp plugin activate ${PLUGIN_SLUG} --quiet
    " > /dev/null 2>&1 || true
}
trap restore EXIT

echo "Running Plugin Check in wp-env ..."
npx wp-env run cli bash -c "
    set -e
    wp plugin is-installed plugin-check || wp plugin install plugin-check --quiet
    wp plugin activate plugin-check --quiet
    rm -rf wp-content/plugins/${CHECK_DIR}
    cp -r wp-content/plugins/${PLUGIN_SLUG}/dist/release-check/${PLUGIN_SLUG} wp-content/plugins/${CHECK_DIR}
    wp plugin deactivate ${PLUGIN_SLUG} --quiet
    wp plugin activate ${CHECK_DIR} --quiet
    set +e
    echo '=== PLUGIN CHECK ==='
    wp plugin check ${CHECK_DIR} \
        --slug=${PLUGIN_SLUG} \
        --mode=new \
        --include-experimental \
        --severity=1 \
        --require=./wp-content/plugins/plugin-check/cli.php \
        --format=table
    rc=\$?
    echo '=== END ==='
    exit \$rc
" 2>&1 | sed -n '/^=== PLUGIN CHECK ===$/,/^=== END ===$/p' | grep -v '^=== '
